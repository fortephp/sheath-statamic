<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Tests\Fixtures;

use Statamic\Tags\Tags;

final class ChangedFixtureTag extends Tags
{
    public function index(): string
    {
        return '';
    }

    public function newlyAddedMethod(): string
    {
        return '';
    }
}
