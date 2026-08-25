<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Antlers\Regions;

final readonly class Issue
{
    public function __construct(
        public string $kind,
        public int $startOffset,
        public int $endOffset,
    ) {}
}
