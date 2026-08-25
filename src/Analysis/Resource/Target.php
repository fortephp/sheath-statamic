<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Resource;

final readonly class Target
{
    public function __construct(
        public ResourceType $type,
        public string $handle,
    ) {}
}
