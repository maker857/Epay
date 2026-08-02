<?php
declare(strict_types=1);

final class PostgresMigrations
{
    public const CURRENT_VERSION = 2056;

    public static function upgradeToCurrent(PDO $pdo, string $prefix, string $installSql): void
    {
        self::validatePrefix($prefix);
        if ($pdo->inTransaction()) {
            throw new RuntimeException('PostgreSQL schema upgrade requires its own transaction.');
        }

        $tables = self::extractCreateTableStatements($installSql);
        if ($tables === []) {
            throw new RuntimeException('Canonical install SQL contains no CREATE TABLE statements.');
        }

        $pdo->beginTransaction();
        try {
            self::reconcileRenamedColumns($pdo, $prefix);
            foreach ($tables as $canonicalTable => $createSql) {
                $table = self::replaceCanonicalPrefix($canonicalTable, $prefix);
                if (!self::tableExists($pdo, $table)) {
                    foreach (self::convertMysqlStatement($createSql, $prefix) as $statement) {
                        $pdo->exec($statement);
                    }
                    continue;
                }

                self::reconcileExistingTable($pdo, $table, $createSql);
            }

            self::reconcileKnownColumnTypes($pdo, $prefix);
            self::upsertCanonicalPaymentTypes($pdo, $prefix, $installSql);
            self::updateVersionAndClearCache($pdo, $prefix);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function needsReconciliation(PDO $pdo, string $prefix, string $installSql): bool
    {
        self::validatePrefix($prefix);
        $tables = self::extractCreateTableStatements($installSql);
        if ($tables === []) {
            throw new RuntimeException('Canonical install SQL contains no CREATE TABLE statements.');
        }

        if (self::columnExists($pdo, $prefix.'_user', 'wxid')) {
            return true;
        }
        foreach ($tables as $canonicalTable => $createSql) {
            $table = self::replaceCanonicalPrefix($canonicalTable, $prefix);
            if (!self::tableExists($pdo, $table)) {
                return true;
            }
            foreach (preg_split('/\R/', $createSql) as $line) {
                $line = trim($line);
                if (preg_match('/^`([^`]+)`\s+/', $line, $columnMatch) && !self::columnExists($pdo, $table, $columnMatch[1])) {
                    return true;
                }
                if (preg_match('/^(UNIQUE\s+)?KEY\s+`?([^`\s(]+)`?\s*\(/i', $line, $indexMatch)) {
                    $indexName = self::indexName($table, $indexMatch[2], trim((string)$indexMatch[1]) !== '');
                    if (!self::indexExists($pdo, $indexName)) {
                        return true;
                    }
                }
            }
        }
        foreach (self::knownColumnTypes($prefix) as [$table, $column, $dataType, $length]) {
            if (self::tableExists($pdo, $table) && self::columnExists($pdo, $table, $column) && !self::columnTypeMatches($pdo, $table, $column, $dataType, $length)) {
                return true;
            }
        }
        return !self::canonicalPaymentTypesExist($pdo, $prefix);
    }

    public static function convertMysqlStatement(string $sql, string $prefix): array
    {
        self::validatePrefix($prefix);
        $sql = str_replace('pre_', $prefix.'_', trim($sql));
        if ($sql === '') {
            return [];
        }

        $sql = preg_replace('/^DROP TABLE IF EXISTS `([^`]+)`/i', 'DROP TABLE IF EXISTS "$1"', $sql);
        if (!preg_match('/^CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`([^`]+)`/i', $sql, $tableMatch)) {
            return [self::mysqlToPostgres($sql)];
        }

        $table = $tableMatch[1];
        $indexes = [];
        $kept = [];
        foreach (preg_split('/\R/', $sql) as $line) {
            if (preg_match('/^\s*(UNIQUE\s+)?KEY\s+`?([^`\s(]+)`?\s*\(([^)]+)\),?\s*$/i', trim($line), $match)) {
                $indexes[] = self::buildIndexSql(
                    $table,
                    $match[2],
                    $match[3],
                    trim((string)$match[1]) !== ''
                );
                continue;
            }
            $kept[] = $line;
        }

        $result = [self::mysqlToPostgres(implode("\n", $kept))];
        foreach ($indexes as $index) {
            $result[] = $index;
        }
        return array_values(array_filter($result, static fn(string $statement): bool => trim($statement) !== ''));
    }

    private static function reconcileExistingTable(PDO $pdo, string $table, string $createSql): void
    {
        foreach (preg_split('/\R/', $createSql) as $line) {
            $line = trim($line);
            if (preg_match('/^`([^`]+)`\s+(.+?)(?:,)?$/', $line, $columnMatch)) {
                $column = $columnMatch[1];
                if (!self::columnExists($pdo, $table, $column)) {
                    $definition = preg_replace('/,\s*$/', '', $columnMatch[2]);
                    $pdo->exec(
                        'ALTER TABLE '.self::quoteIdentifier($table).
                        ' ADD COLUMN IF NOT EXISTS '.self::quoteIdentifier($column).' '.
                        self::mysqlToPostgres($definition)
                    );
                }
                continue;
            }

            if (preg_match('/^(UNIQUE\s+)?KEY\s+`?([^`\s(]+)`?\s*\(([^)]+)\),?$/i', $line, $indexMatch)) {
                $pdo->exec(self::buildIndexSql(
                    $table,
                    $indexMatch[2],
                    $indexMatch[3],
                    trim((string)$indexMatch[1]) !== ''
                ));
            }
        }
    }

    private static function reconcileRenamedColumns(PDO $pdo, string $prefix): void
    {
        $table = $prefix.'_user';
        if (!self::tableExists($pdo, $table) || !self::columnExists($pdo, $table, 'wxid')) {
            return;
        }
        if (!self::columnExists($pdo, $table, 'wx_uid')) {
            $pdo->exec(
                'ALTER TABLE '.self::quoteIdentifier($table).
                ' RENAME COLUMN '.self::quoteIdentifier('wxid').' TO '.self::quoteIdentifier('wx_uid')
            );
            return;
        }
        $pdo->exec(
            'UPDATE '.self::quoteIdentifier($table).
            ' SET '.self::quoteIdentifier('wx_uid').'='.self::quoteIdentifier('wxid').
            ' WHERE '.self::quoteIdentifier('wx_uid').' IS NULL AND '.self::quoteIdentifier('wxid').' IS NOT NULL'
        );
        $pdo->exec(
            'ALTER TABLE '.self::quoteIdentifier($table).' DROP COLUMN '.self::quoteIdentifier('wxid')
        );
    }

    private static function reconcileKnownColumnTypes(PDO $pdo, string $prefix): void
    {
        foreach (self::knownColumnTypes($prefix) as [$table, $column, $dataType, $length, $sqlType]) {
            if (self::tableExists($pdo, $table) && self::columnExists($pdo, $table, $column) && !self::columnTypeMatches($pdo, $table, $column, $dataType, $length)) {
                $pdo->exec(
                    'ALTER TABLE '.self::quoteIdentifier($table).
                    ' ALTER COLUMN '.self::quoteIdentifier($column).' TYPE '.$sqlType
                );
            }
        }
    }

    private static function knownColumnTypes(string $prefix): array
    {
        return [
            [$prefix.'_plugin', 'types', 'character varying', 500, 'varchar(500)'],
            [$prefix.'_plugin', 'transtypes', 'character varying', 500, 'varchar(500)'],
            [$prefix.'_user', 'pwd', 'character varying', 255, 'varchar(255)'],
            [$prefix.'_user', 'msgconfig', 'text', null, 'text'],
            [$prefix.'_order', 'ip', 'character varying', 50, 'varchar(50)'],
            [$prefix.'_log', 'ip', 'character varying', 50, 'varchar(50)'],
            [$prefix.'_regcode', 'ip', 'character varying', 50, 'varchar(50)'],
            [$prefix.'_order', 'buyer', 'character varying', 100, 'varchar(100)'],
        ];
    }

    private static function upsertCanonicalPaymentTypes(PDO $pdo, string $prefix, string $installSql): void
    {
        $table = $prefix.'_type';
        if (!self::tableExists($pdo, $table)) {
            return;
        }

        if (!preg_match_all('/INSERT INTO `pre_type` VALUES\s*\((6|7),\s*(.+?)\);/i', $installSql, $matches, PREG_SET_ORDER)) {
            throw new RuntimeException('Canonical payment types 6 and 7 are missing.');
        }

        $found = [];
        foreach ($matches as $match) {
            $id = (int)$match[1];
            $found[$id] = true;
            $sql = 'INSERT INTO '.self::quoteIdentifier($table).' VALUES ('.$id.', '.$match[2].')'.
                ' ON CONFLICT ("id") DO UPDATE SET '.
                '"name"=EXCLUDED."name", "device"=EXCLUDED."device", '.
                '"showname"=EXCLUDED."showname", "status"=EXCLUDED."status"';
            $pdo->exec($sql);
        }
        if (!isset($found[6], $found[7])) {
            throw new RuntimeException('Canonical payment types 6 and 7 are incomplete.');
        }

        $sequence = $pdo->query(
            'SELECT pg_get_serial_sequence('.$pdo->quote($table).", 'id')"
        )->fetchColumn();
        if ($sequence) {
            $pdo->exec(
                'SELECT setval('.$pdo->quote($sequence).', '.
                '(SELECT COALESCE(MAX("id"), 1) FROM '.self::quoteIdentifier($table).'), true)'
            );
        }
    }

    private static function canonicalPaymentTypesExist(PDO $pdo, string $prefix): bool
    {
        $table = $prefix.'_type';
        if (!self::tableExists($pdo, $table)) {
            return false;
        }
        $statement = $pdo->query(
            'SELECT "id", "name" FROM '.self::quoteIdentifier($table).' WHERE "id" IN (6,7)'
        );
        $types = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
        return $types === [6=>'paypal', 7=>'douyinpay'] || $types === [7=>'douyinpay', 6=>'paypal'];
    }

    private static function updateVersionAndClearCache(PDO $pdo, string $prefix): void
    {
        $configTable = $prefix.'_config';
        if (!self::tableExists($pdo, $configTable)) {
            throw new RuntimeException('Configuration table is missing after schema upgrade.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO '.self::quoteIdentifier($configTable).' ("k", "v") VALUES (\'version\', :version) '.
            'ON CONFLICT ("k") DO UPDATE SET "v"=EXCLUDED."v"'
        );
        $statement->execute([':version'=>(string)self::CURRENT_VERSION]);

        $cacheTable = $prefix.'_cache';
        if (self::tableExists($pdo, $cacheTable)) {
            $pdo->exec('UPDATE '.self::quoteIdentifier($cacheTable).' SET "v"=\'\' WHERE "k"=\'config\'');
        }
    }

    private static function extractCreateTableStatements(string $installSql): array
    {
        preg_match_all(
            '/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`([^`]+)`\s*\(.*?\)\s*ENGINE\s*=\s*\w+[^;]*;/is',
            $installSql,
            $matches,
            PREG_SET_ORDER
        );

        $tables = [];
        foreach ($matches as $match) {
            $tables[$match[1]] = trim($match[0]);
        }
        return $tables;
    }

    private static function buildIndexSql(string $table, string $sourceName, string $columns, bool $unique): string
    {
        $indexName = self::indexName($table, $sourceName, $unique);
        $columnSql = self::mysqlToPostgres($columns);
        return 'CREATE '.($unique ? 'UNIQUE ' : '').'INDEX IF NOT EXISTS '.
            self::quoteIdentifier($indexName).' ON '.self::quoteIdentifier($table).' ('.$columnSql.')';
    }

    private static function indexName(string $table, string $sourceName, bool $unique): string
    {
        $suffix = $unique ? '_uidx' : '_idx';
        $name = $table.'_'.$sourceName.$suffix;
        if (strlen($name) <= 63) {
            return $name;
        }
        return substr($name, 0, 54).'_'.substr(sha1($name), 0, 8);
    }

    private static function mysqlToPostgres(string $sql): string
    {
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
        $sql = preg_replace('/\)\s*ENGINE\s*=\s*\w+[^;]*;?$/i', ')', $sql);
        $sql = preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql);
        $sql = preg_replace('/\s+COLLATE\s*=\s*\w+/i', '', $sql);
        $sql = preg_replace('/\bUNIQUE KEY\s+"([^"]+)"\s*\(([^)]+)\)/i', 'CONSTRAINT "$1" UNIQUE ($2)', $sql);
        $sql = preg_replace('/,\s*\)/s', "\n)", $sql);
        $sql = preg_replace('/,\s*$/', '', trim($sql));
        return trim(preg_replace('/[ \t]+/', ' ', $sql));
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables '.
            'WHERE table_schema=current_schema() AND table_name=:table'
        );
        $statement->execute([':table'=>$table]);
        return (int)$statement->fetchColumn() === 1;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns '.
            'WHERE table_schema=current_schema() AND table_name=:table AND column_name=:column'
        );
        $statement->execute([':table'=>$table, ':column'=>$column]);
        return (int)$statement->fetchColumn() === 1;
    }

    private static function columnTypeMatches(PDO $pdo, string $table, string $column, string $dataType, ?int $length): bool
    {
        $statement = $pdo->prepare(
            'SELECT data_type, character_maximum_length FROM information_schema.columns '.
            'WHERE table_schema=current_schema() AND table_name=:table AND column_name=:column'
        );
        $statement->execute([':table'=>$table, ':column'=>$column]);
        $actual = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$actual || $actual['data_type'] !== $dataType) {
            return false;
        }
        return $length === null || (int)$actual['character_maximum_length'] === $length;
    }

    private static function indexExists(PDO $pdo, string $indexName): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM pg_indexes WHERE schemaname=current_schema() AND indexname=:index'
        );
        $statement->execute([':index'=>$indexName]);
        return (int)$statement->fetchColumn() === 1;
    }

    private static function replaceCanonicalPrefix(string $table, string $prefix): string
    {
        return strncmp($table, 'pre_', 4) === 0 ? $prefix.'_'.substr($table, 4) : $table;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private static function validatePrefix(string $prefix): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $prefix)) {
            throw new InvalidArgumentException('Invalid database prefix.');
        }
    }
}
