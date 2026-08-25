<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Parameter;

final readonly class StaticValue
{
    private function __construct(
        public bool $present,
        public bool $known,
        public ?string $value,
    ) {}

    public static function missing(): self
    {
        return new self(false, false, null);
    }

    public static function unknown(): self
    {
        return new self(true, false, null);
    }

    public static function literal(string $value): self
    {
        return new self(true, true, $value);
    }
}
