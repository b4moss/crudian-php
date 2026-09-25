<?php

declare(strict_types=1);

namespace B4moss\Crudian;

final class CrudianError extends \RuntimeException
{
    public static function of(string $message): self
    {
        return new self($message);
    }
}
