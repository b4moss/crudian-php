<?php

declare(strict_types=1);

namespace B4moss\Crudian;

final class GroupNode implements WhereNode
{
    /**
     * @param list<WhereNode> $children
     */
    public function __construct(
        public readonly string $type,
        public readonly array $children,
    ) {
    }
}
