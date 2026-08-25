<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\SheathManager;
use Forte\Sheath\Statamic\Tests\TestCase;
use Illuminate\Support\Collection;

uses(TestCase::class)->in('Feature');

/** @return list<Violation> */
function lintStatamic(string $rule, string $source): array
{
    $config = Config::make()->setRule($rule, 'error');

    return array_values(app(SheathManager::class)->lint($source, 'test.blade.php', $config)->violations);
}

/** @return Collection<array-key, mixed> */
function statamicRegistry(string $binding): Collection
{
    $registry = app($binding);
    if (! $registry instanceof Collection) {
        throw new RuntimeException("Statamic registry [{$binding}] is unavailable.");
    }

    return $registry;
}

function templateOffset(string $source, string $needle, bool $last = false): int
{
    $offset = $last ? strrpos($source, $needle) : strpos($source, $needle);
    if ($offset === false) {
        throw new RuntimeException("Template fixture does not contain [{$needle}].");
    }

    return $offset;
}
