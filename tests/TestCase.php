<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Tests;

use Forte\Sheath\ServiceProvider as SheathServiceProvider;
use Forte\Sheath\Statamic\ServiceProvider;
use Forte\Sheath\Statamic\Tests\Fixtures\FixtureDictionary;
use Forte\Sheath\Statamic\Tests\Fixtures\FixtureTag;
use Forte\Sheath\Statamic\Tests\Fixtures\WildcardTag;
use Illuminate\Support\Collection;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Statamic\Providers\StatamicServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            StatamicServiceProvider::class,
            SheathServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $tags = $this->registry('statamic.tags');
        $tags->put('fixture', FixtureTag::class);
        $tags->put('wildcard_fixture', WildcardTag::class);

        $dictionaries = $this->registry('statamic.dictionaries');
        $dictionaries->put('fixture_dictionary', FixtureDictionary::class);
    }

    /** @return Collection<array-key, mixed> */
    private function registry(string $binding): Collection
    {
        $registry = app($binding);
        if (! $registry instanceof Collection) {
            throw new \RuntimeException("Statamic registry [{$binding}] is unavailable.");
        }

        return $registry;
    }
}
