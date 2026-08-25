<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Tests\Fixtures;

use Statamic\Dictionaries\Dictionary;
use Statamic\Dictionaries\Item;

final class FixtureDictionary extends Dictionary
{
    /** @var string */
    protected static $handle = 'fixture_dictionary';

    /** @return array<string, string> */
    public function options(?string $search = null): array
    {
        return [];
    }

    public function get(string $key): ?Item
    {
        return null;
    }
}
