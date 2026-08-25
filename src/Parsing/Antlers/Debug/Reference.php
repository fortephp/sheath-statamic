<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Antlers\Debug;

final readonly class Reference
{
    public function __construct(
        public string $kind,
        public string $name,
        public int $start,
        public int $end,
    ) {}
}
