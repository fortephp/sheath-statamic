<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Tests\Fixtures;

use Statamic\Tags\Tags;

final class WildcardTag extends Tags
{
    public function wildcard(): string
    {
        return '';
    }
}
