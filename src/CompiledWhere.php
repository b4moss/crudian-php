<?php

declare(strict_types=1);

namespace B4moss\Crudian;

final class CompiledWhere
{
    /**
     * @param list<mixed> $args
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $args,
        public readonly int $nextIndex,
    ) {
    }
}
