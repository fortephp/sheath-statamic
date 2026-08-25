<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Tests\Fixtures;

use Statamic\Tags\Tags;

final class FixtureTag extends Tags
{
    public function index(): string
    {
        return '';
    }

    public function knownMethod(): string
    {
        return '';
    }

    public function needsArgument(string $value): string
    {
        return $value;
    }
}
