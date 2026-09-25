<?php

declare(strict_types=1);

namespace B4moss\Crudian;

interface Executor
{
    /**
     * @param list<mixed> $args
     */
    public function run(string $sql, array $args = []): int;

    /**
     * @param list<mixed> $args
     * @return array<string, mixed>|null
     */
    public function get(string $sql, array $args = []): ?array;

    /**
     * @param list<mixed> $args
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $args = []): array;

    /**
     * @param callable(Executor): void $fn
     */
    public function transaction(callable $fn): void;
}
