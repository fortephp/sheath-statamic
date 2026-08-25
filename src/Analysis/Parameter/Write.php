<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Parameter;

final readonly class Write
{
    /**
     * @param int $sequence
     * @param string|null $name
     * @param 'empty'|'zero'|'truthy'|'dynamic' $value
     * @param string|null $literal
     */
    public function __construct(
        public int $sequence,
        public ?string $name,
        public string $value,
        public ?string $literal,
    ) {}
}
