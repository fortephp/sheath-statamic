<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Antlers\Regions;

final readonly class Region
{
    public function __construct(
        public int $startOffset,
        public int $contentStartOffset,
        public int $contentEndOffset,
        public int $endOffset,
        public bool $nested = false,
    ) {}
}
