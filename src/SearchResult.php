<?php

declare(strict_types=1);

namespace B4moss\Crudian;

final class SearchResult
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly bool $hasMore,
        public readonly int $total,
        public readonly mixed $nextCursor = null,
        public readonly int $offset = 0,
        public readonly int $limit = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $paging): array
    {
        if ($paging === 'cursor') {
            return [
                'items' => $this->items,
                'nextCursor' => $this->nextCursor,
                'hasMore' => $this->hasMore,
                'total' => $this->total,
            ];
        }

        return [
            'items' => $this->items,
            'total' => $this->total,
            'offset' => $this->offset,
            'limit' => $this->limit,
            'hasMore' => $this->hasMore,
        ];
    }
}
