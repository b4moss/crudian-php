<?php

declare(strict_types=1);

namespace B4moss\Crudian;

final class WhereBuilder
{
    private function __construct(private readonly WhereNode $node)
    {
    }

    public static function create(): self
    {
        return new self(new GroupNode('and', []));
    }

    public function toNode(): WhereNode
    {
        return $this->node;
    }

    private function appendCond(string $op, string $column, mixed $value = null): self
    {
        $next = new CondNode($op, $column, $value);
        if ($this->node instanceof GroupNode && $this->node->type === 'and') {
            $children = [...$this->node->children, $next];

            return new self(new GroupNode('and', $children));
        }

        return new self(new GroupNode('and', [$this->node, $next]));
    }

    public function eq(string $column, mixed $value): self
    {
        return $this->appendCond('eq', $column, $value);
    }

    public function ne(string $column, mixed $value): self
    {
        return $this->appendCond('ne', $column, $value);
    }

    public function lt(string $column, mixed $value): self
    {
        return $this->appendCond('lt', $column, $value);
    }

    public function gt(string $column, mixed $value): self
    {
        return $this->appendCond('gt', $column, $value);
    }

    public function lte(string $column, mixed $value): self
    {
        return $this->appendCond('lte', $column, $value);
    }

    public function gte(string $column, mixed $value): self
    {
        return $this->appendCond('gte', $column, $value);
    }

    public function in(string $column, mixed $value): self
    {
        return $this->appendCond('in', $column, $value);
    }

    public function like(string $column, mixed $value): self
    {
        return $this->appendCond('like', $column, $value);
    }

    public function isNull(string $column): self
    {
        return $this->appendCond('isNull', $column);
    }

    public function isNotNull(string $column): self
    {
        return $this->appendCond('isNotNull', $column);
    }

    public function and(self $other): self
    {
        return new self(new GroupNode('and', [$this->node, $other->node]));
    }

    public function or(self $other): self
    {
        return new self(new GroupNode('or', [$this->node, $other->node]));
    }
}
