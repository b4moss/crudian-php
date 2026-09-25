<?php

declare(strict_types=1);

namespace B4moss\Crudian;

final class CondNode implements WhereNode
{
    public function __construct(
        public readonly string $op,
        public readonly string $column,
        public readonly mixed $value = null,
    ) {
    }
}
