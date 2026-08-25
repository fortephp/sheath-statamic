<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Tag;

use Forte\Sheath\Statamic\Analysis\Parameter\State;
use Forte\Sheath\Statamic\Analysis\Parameter\StaticSource;
use Forte\Sheath\Statamic\Analysis\Parameter\StaticValue;
use Forte\Sheath\Statamic\Analysis\Parameter\Write;

abstract readonly class Invocation implements StaticSource
{
    /**
     * @param  list<Write>  $parameterWrites
     */
    public function __construct(
        public string $handle,
        public ?string $method,
        public int $start,
        public int $end,
        public array $parameterWrites,
    ) {}

    public function hasParameter(array $aliases): bool
    {
        return State::hasWrite($this->parameterWrites, $aliases);
    }

    public function firstStaticParameter(array $aliases): StaticValue
    {
        return State::firstStaticFromWrites($this->parameterWrites, $aliases);
    }

    public function firstStaticTruthiness(array $aliases): ?bool
    {
        return State::firstTruthinessFromWrites($this->parameterWrites, $aliases);
    }

    public function displayName(): string
    {
        return $this->handle.($this->method === null ? '' : ':'.$this->method);
    }
}
