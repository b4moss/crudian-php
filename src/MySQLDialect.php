<?php

declare(strict_types=1);

namespace B4moss\Crudian;

final class MySQLDialect implements Dialect
{
    public function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function placeholder(int $n): string
    {
        return '?';
    }

    public function supportsInsertReturning(): bool
    {
        return false;
    }

    public function lastInsertIdSql(): string
    {
        return 'SELECT LAST_INSERT_ID() AS id';
    }

    public function describeColumns(string $table): array
    {
        return [
            'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        ];
    }
}
