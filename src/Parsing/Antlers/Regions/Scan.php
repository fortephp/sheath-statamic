<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Antlers\Regions;

final readonly class Scan
{
    /**
     * @param  list<Region>  $regions
     * @param  list<Issue>  $issues
     */
    public function __construct(
        public array $regions,
        public array $issues,
    ) {}
}
