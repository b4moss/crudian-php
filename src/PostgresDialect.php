<?php

declare(strict_types=1);

namespace B4moss\Crudian;

final class PostgresDialect implements Dialect
{
    public function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function placeholder(int $n): string
    {
        return '$' . $n;
    }

    public function supportsInsertReturning(): bool
    {
        return true;
    }

    public function lastInsertIdSql(): string
    {
        return 'SELECT lastval() AS id';
    }

    public function describeColumns(string $table): array
    {
        return [
            'SELECT column_name AS name FROM information_schema.columns'
            . ' WHERE table_schema = current_schema() AND table_name = $1',
            [$table],
        ];
    }
}
