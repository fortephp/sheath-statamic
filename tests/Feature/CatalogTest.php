<?php

declare(strict_types=1);

use Forte\Sheath\Statamic\Catalog\TagCatalog;
use Forte\Sheath\Statamic\Tests\Fixtures\ChangedFixtureTag;
use Forte\Sheath\Statamic\Tests\Fixtures\FixtureTag;
use Statamic\Tags\Collection\Collection as CollectionTag;
use Statamic\Tags\Tags;

it('changes its cache context when a registered tag implementation changes', function (): void {
    $before = app(TagCatalog::class)->cacheContext()['fixture'];

    app()->forgetScopedInstances();
    $tags = statamicRegistry('statamic.tags');

    $tags->put('fixture', ChangedFixtureTag::class);
    $after = app(TagCatalog::class)->cacheContext()['fixture'];

    expect($before['signature'])->not->toBe($after['signature'])
        ->and($before['class'])->not->toBe($after['class']);
});

it('refreshes registry bindings between repeated commands in one application', function (): void {
    $catalog = app(TagCatalog::class);
    $tags = statamicRegistry('statamic.tags');

    expect($catalog->has('late_fixture'))->toBeFalse()
        ->and($catalog->cacheContext())->not->toHaveKey('late_fixture');

    $tags->put('late_fixture', FixtureTag::class);
    $context = $catalog->cacheContext();

    expect($context)->toHaveKey('late_fixture')
        ->and($catalog->has('late_fixture'))->toBeTrue()
        ->and(is_a($context['late_fixture']['class'], FixtureTag::class, true))->toBeTrue();
});

it('identifies only tag implementations shipped by Statamic as core', function (): void {
    $catalog = app(TagCatalog::class);
    $tags = statamicRegistry('statamic.tags');

    expect($catalog->isCore('collection'))->toBeTrue()
        ->and($catalog->isCore('cache'))->toBeTrue()
        ->and($catalog->isCore('foreach'))->toBeTrue()
        ->and($catalog->isCore('fixture'))->toBeFalse();

    $replacement = new class extends CollectionTag {};
    $tags->put('collection', $replacement::class);
    $tags->put('custom_collection', CollectionTag::class);

    expect($catalog->isCore('collection'))->toBeFalse()
        ->and($catalog->isCore('custom_collection'))->toBeFalse();
});

it('does not let dynamic dispatch hide an invalid concrete method', function (): void {
    $tags = statamicRegistry('statamic.tags');

    $tag = new class extends Tags
    {
        public function needsArgument(string $value): string
        {
            return $value;
        }

        public function __call(mixed $method, mixed $args): mixed
        {
            return null;
        }
    };

    $tags->put('dynamic_fixture', $tag::class);
    $catalog = app(TagCatalog::class);

    expect($catalog->acceptsMethod('dynamic_fixture', 'anything'))->toBeTrue()
        ->and($catalog->acceptsMethod('dynamic_fixture', 'needs_argument'))->toBeFalse();
});
