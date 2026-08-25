<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\TagsDirective;

final readonly class Issue
{
    public function __construct(
        public int $start,
        public int $end,
    ) {}
}
