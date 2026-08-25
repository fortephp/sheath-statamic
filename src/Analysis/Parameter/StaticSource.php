<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Parameter;

interface StaticSource
{
    /** @param list<string> $aliases */
    public function hasParameter(array $aliases): bool;

    /** @param list<string> $aliases */
    public function firstStaticParameter(array $aliases): StaticValue;

    /** @param list<string> $aliases */
    public function firstStaticTruthiness(array $aliases): ?bool;
}
