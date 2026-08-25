<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Antlers\Regions;

use Throwable;

/** @internal */
final readonly class ParseResult
{
    /** @param list<mixed> $nodes */
    private function __construct(
        public array $nodes,
        public ?Throwable $failure,
    ) {}

    /** @param list<mixed> $nodes */
    public static function success(array $nodes): self
    {
        return new self($nodes, null);
    }

    public static function failure(Throwable $failure): self
    {
        return new self([], $failure);
    }
}
