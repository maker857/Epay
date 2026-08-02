<?php
declare(strict_types=1);

require_once '/var/www/html/includes/lib/PasswordHasher.php';
require_once '/var/www/html/docker/PostgresBootstrap.php';
require_once '/var/www/html/docker/PostgresMigrations.php';

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

$table = $prefix.'_config';
$exists = (bool)$pdo->query('SELECT to_regclass('.$pdo->quote($table).')')->fetchColumn();
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
            foreach (PostgresMigrations::convertMysqlStatement($statement, $prefix) as $converted) {
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
        $insert = $pdo->prepare(
            'INSERT INTO '.quote_postgres_identifier($table).' (k,v) VALUES (:k,:v) '.
            'ON CONFLICT (k) DO UPDATE SET v=EXCLUDED.v'
        );
        foreach ($config as $key => $value) $insert->execute([':k'=>$key, ':v'=>$value]);
        $pdo->commit();
        fwrite(STDOUT, "PostgreSQL schema initialized\n");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "PostgreSQL schema initialization failed: {$e->getMessage()}\nStatement: {$currentStatement}\n");
        exit(1);
    }
} else {
    upgrade_schema($pdo, $prefix, $table, '/var/www/html/install/install.sql');
}

PostgresBootstrap::ensureEmptyIdentityStartsAt($pdo, $prefix.'_user', 'uid', 1000);

function upgrade_schema(PDO $pdo, string $prefix, string $configTable, string $installFile): void
{
    $version = (int)$pdo->query(
        'SELECT v FROM '.quote_postgres_identifier($configTable)." WHERE k='version'"
    )->fetchColumn();

    try {
        $installSql = file_get_contents($installFile);
        if ($installSql === false) {
            throw new RuntimeException('install.sql not found');
        }
        $schemaChanged = $version < PostgresMigrations::CURRENT_VERSION ||
            PostgresMigrations::needsReconciliation($pdo, $prefix, $installSql);
        if ($schemaChanged) {
            PostgresMigrations::upgradeToCurrent($pdo, $prefix, $installSql);
        }

        $pdo->beginTransaction();
        foreach (['admin_pwd', 'admin_paypwd'] as $key) {
            $statement = $pdo->prepare(
                'SELECT v FROM '.quote_postgres_identifier($configTable).' WHERE k=:k'
            );
            $statement->execute([':k'=>$key]);
            $stored = (string)$statement->fetchColumn();
            if ($stored !== '' && !\lib\PasswordHasher::isModern($stored)) {
                $update = $pdo->prepare(
                    'UPDATE '.quote_postgres_identifier($configTable).' SET v=:v WHERE k=:k'
                );
                $update->execute([':v'=>\lib\PasswordHasher::hash($stored), ':k'=>$key]);
            }
        }
        $pdo->commit();
        if ($schemaChanged) {
            fwrite(
                STDOUT,
                "PostgreSQL schema upgraded from {$version} to ".PostgresMigrations::CURRENT_VERSION."\n"
            );
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "PostgreSQL schema upgrade failed: {$e->getMessage()}\n");
        exit(1);
    }
}

function quote_postgres_identifier(string $identifier): string
{
    return '"'.str_replace('"', '""', $identifier).'"';
}
