<?php

final class PostgresBootstrap
{
    public static function ensureEmptyIdentityStartsAt(PDO $pdo, string $table, string $column, int $start): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $table) || !preg_match('/^[a-z][a-z0-9_]*$/', $column)) {
            throw new InvalidArgumentException('Invalid PostgreSQL identifier');
        }
        if ($start < 1) {
            throw new InvalidArgumentException('Identity start must be positive');
        }

        $count = (int)$pdo->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn();
        if ($count === 0) {
            $pdo->exec('ALTER TABLE "'.$table.'" ALTER COLUMN "'.$column.'" RESTART WITH '.$start);
        }
    }
}
