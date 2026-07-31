<?php
declare(strict_types=1);

require_once '/var/www/html/includes/lib/PasswordHasher.php';

$host = getenv('DB_HOST') ?: 'db';
$port = getenv('DB_PORT') ?: '5432';
$name = getenv('DB_NAME') ?: 'epay';
$user = getenv('DB_USER') ?: 'epay';
$password = getenv('DB_PASSWORD') ?: '';
$prefix = getenv('DB_PREFIX') ?: 'pay';
$adminUser = getenv('ADMIN_USER') ?: 'admin';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';
$adminPayPassword = getenv('ADMIN_PAY_PASSWORD') ?: $adminPassword;

if (!preg_match('/^[a-z][a-z0-9_]*$/', $prefix)) {
    fwrite(STDERR, "DB_PREFIX must contain only lowercase letters, numbers and underscores\n");
    exit(1);
}
if ($adminPassword === '' || $adminPayPassword === '') {
    fwrite(STDERR, "ADMIN_PASSWORD and ADMIN_PAY_PASSWORD must be configured\n");
    exit(1);
}

$pdo = null;
for ($attempt = 1; $attempt <= 60; $attempt++) {
    try {
        $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$name}", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
        break;
    } catch (Throwable $e) {
        if ($attempt === 60) {
            fwrite(STDERR, "Unable to connect to PostgreSQL: {$e->getMessage()}\n");
            exit(1);
        }
        sleep(2);
    }
}

$table = $prefix . '_config';
$exists = (bool)$pdo->query("SELECT to_regclass(" . $pdo->quote($table) . ")")->fetchColumn();
if (!$exists) {
    $sql = file_get_contents('/var/www/html/install/install.sql');
    if ($sql === false) {
        throw new RuntimeException('install.sql not found');
    }
    $pdo->beginTransaction();
    $currentStatement = '';
    try {
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') continue;
            $currentStatement = $statement;
            foreach (convert_mysql_statement($statement, $prefix) as $converted) {
                if (trim($converted) !== '') $pdo->exec($converted);
            }
        }
        $config = [
            'syskey' => bin2hex(random_bytes(16)),
            'build' => date('Y-m-d'),
            'cronkey' => (string)random_int(111111, 999999),
            'admin_user' => $adminUser,
            'admin_pwd' => \lib\PasswordHasher::hash($adminPassword),
            'admin_paypwd' => \lib\PasswordHasher::hash($adminPayPassword),
        ];
        $insert = $pdo->prepare("INSERT INTO \"{$table}\" (k,v) VALUES (:k,:v) ON CONFLICT (k) DO UPDATE SET v=EXCLUDED.v");
        foreach ($config as $key => $value) $insert->execute([':k' => $key, ':v' => $value]);
        $pdo->commit();
        fwrite(STDOUT, "PostgreSQL schema initialized\n");
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "PostgreSQL schema initialization failed: {$e->getMessage()}\nStatement: {$currentStatement}\n");
        exit(1);
    }
} else {
    upgrade_schema($pdo, $prefix, $table);
}

function upgrade_schema(PDO $pdo, string $prefix, string $configTable): void {
    $version = (int)$pdo->query("SELECT v FROM \"{$configTable}\" WHERE k='version'")->fetchColumn();
    if ($version >= 2056) return;

    $pdo->beginTransaction();
    try {
        $pdo->exec("ALTER TABLE \"{$prefix}_order\" ADD COLUMN IF NOT EXISTS province varchar(2) DEFAULT NULL");
        $pdo->exec("ALTER TABLE \"{$prefix}_psreceiver\" ADD COLUMN IF NOT EXISTS mode smallint NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE \"{$prefix}_plugin\" ALTER COLUMN types TYPE varchar(500)");
        $pdo->exec("ALTER TABLE \"{$prefix}_plugin\" ALTER COLUMN transtypes TYPE varchar(500)");
        $pdo->exec("ALTER TABLE \"{$prefix}_user\" ALTER COLUMN pwd TYPE varchar(255)");
        $pdo->exec("INSERT INTO \"{$prefix}_type\" (id,name,device,showname,status) VALUES (7,'douyinpay',0,'抖音支付',0) ON CONFLICT (id) DO UPDATE SET name=EXCLUDED.name,device=EXCLUDED.device,showname=EXCLUDED.showname,status=EXCLUDED.status");

        $sequence = $pdo->query("SELECT pg_get_serial_sequence(" . $pdo->quote($prefix . '_type') . ", 'id')")->fetchColumn();
        if ($sequence) {
            $pdo->exec("SELECT setval(" . $pdo->quote($sequence) . ", (SELECT MAX(id) FROM \"{$prefix}_type\"), true)");
        }

        foreach (['admin_pwd', 'admin_paypwd'] as $key) {
            $statement = $pdo->prepare("SELECT v FROM \"{$configTable}\" WHERE k=:k");
            $statement->execute([':k'=>$key]);
            $stored = (string)$statement->fetchColumn();
            if ($stored !== '' && !\lib\PasswordHasher::isModern($stored)) {
                $update = $pdo->prepare("UPDATE \"{$configTable}\" SET v=:v WHERE k=:k");
                $update->execute([':v'=>\lib\PasswordHasher::hash($stored), ':k'=>$key]);
            }
        }
        $pdo->exec("UPDATE \"{$configTable}\" SET v='2056' WHERE k='version'");
        $pdo->exec("UPDATE \"{$prefix}_cache\" SET v='' WHERE k='config'");
        $pdo->commit();
        fwrite(STDOUT, "PostgreSQL schema upgraded from {$version} to 2056\n");
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "PostgreSQL schema upgrade failed: {$e->getMessage()}\n");
        exit(1);
    }
}

function convert_mysql_statement(string $sql, string $prefix): array {
    $sql = str_replace('pre_', $prefix . '_', $sql);
    $sql = preg_replace('/^DROP TABLE IF EXISTS `([^`]+)`/i', 'DROP TABLE IF EXISTS "$1"', $sql);
    if (!preg_match('/^CREATE TABLE\s+`([^`]+)`/i', $sql, $tableMatch)) {
        return [mysql_to_pg($sql)];
    }
    $table = $tableMatch[1];
    $indexes = [];
    $lines = preg_split('/\R/', $sql);
    $kept = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*KEY\s+`([^`]+)`\s*\(([^)]+)\),?\s*$/i', trim($line), $m)) {
            $indexName = $table . '_' . $m[1] . '_idx';
            $indexes[] = 'CREATE INDEX "' . $indexName . '" ON "' . $table . '" (' . $m[2] . ')';
            continue;
        }
        $kept[] = $line;
    }
    $create = mysql_to_pg(implode("\n", $kept));
    $result = [$create];
    foreach ($indexes as $index) $result[] = mysql_to_pg($index);
    return $result;
}

function mysql_to_pg(string $sql): string {
    $sql = str_replace('`', '"', $sql);
    $sql = preg_replace('/\b(unsigned|ZEROFILL)\b/i', '', $sql);
    $sql = preg_replace('/\b(bigint|int|mediumint|smallint|tinyint)\(\d+\)/i', '$1', $sql);
    $sql = preg_replace('/\btinyint\b/i', 'smallint', $sql);
    $sql = preg_replace('/\bmediumint\b/i', 'integer', $sql);
    $sql = preg_replace('/\bdouble\([^)]*\)/i', 'double precision', $sql);
    $sql = preg_replace('/\bfloat\([^)]*\)/i', 'real', $sql);
    $sql = preg_replace('/\bdatetime\b/i', 'timestamp', $sql);
    $sql = preg_replace('/\b(longtext|mediumtext|tinytext)\b/i', 'text', $sql);
    $sql = preg_replace('/\b(int|integer|bigint)\s+NOT NULL\s+auto_increment/i', '$1 GENERATED BY DEFAULT AS IDENTITY NOT NULL', $sql);
    $sql = preg_replace('/\b(int|integer|bigint)\s+auto_increment/i', '$1 GENERATED BY DEFAULT AS IDENTITY', $sql);
    $sql = preg_replace('/\)\s*ENGINE\s*=\s*\w+[^;]*$/i', ')', $sql);
    $sql = preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql);
    $sql = preg_replace('/\s+COLLATE\s*=\s*\w+/i', '', $sql);
    $sql = preg_replace('/\bUNIQUE KEY\s+"([^"]+)"\s*\(([^)]+)\)/i', 'CONSTRAINT "$1" UNIQUE ($2)', $sql);
    $sql = preg_replace('/,\s*\)/s', "\n)", $sql);
    $sql = preg_replace('/,\s*$/', '', trim($sql));
    return trim($sql);
}
