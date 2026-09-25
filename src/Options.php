<?php

declare(strict_types=1);

namespace B4moss\Crudian;

final class Options
{
    public function __construct(
        public readonly ?string $pk = null,
        public readonly ?Dialect $dialect = null,
        public readonly ?string $driver = null,
    ) {
    }
}
