<?php

declare(strict_types=1);

namespace B4moss\Crudian;

final class SqliteDialect implements Dialect
{
    public function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function placeholder(int $n): string
    {
        return '?';
    }

    public function supportsInsertReturning(): bool
    {
        return true;
    }

    public function lastInsertIdSql(): string
    {
        return 'SELECT last_insert_rowid() AS id';
    }

    public function describeColumns(string $table): array
    {
        return ['PRAGMA table_info(' . $this->quoteIdent($table) . ')', []];
    }
}
