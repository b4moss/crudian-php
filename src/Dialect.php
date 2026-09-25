<?php

declare(strict_types=1);

namespace B4moss\Crudian;

interface Dialect
{
    public function quoteIdent(string $name): string;

    /** 1-based index into statement args. */
    public function placeholder(int $n): string;

    public function supportsInsertReturning(): bool;

    public function lastInsertIdSql(): string;

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function describeColumns(string $table): array;
}
