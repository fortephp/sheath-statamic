<?php

declare(strict_types=1);

use Forte\Sheath\Statamic\Analysis\Resource\ResourceType;
use Forte\Sheath\Statamic\Catalog\ProjectCatalog;
use Forte\Sheath\Statamic\Rules\CollectionMethodCompatibilityRule;
use Forte\Sheath\Statamic\Rules\PartialExistsRule;
use Forte\Sheath\Statamic\Rules\ProjectAwareRule;
use Forte\Sheath\Statamic\Rules\ResourceExistsRule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Statamic\Contracts\Auth\Role as RoleContract;
use Statamic\Contracts\Auth\UserGroup as UserGroupContract;
use Statamic\Entries\Collection as CollectionModel;
use Statamic\Entries\Entry as EntryModel;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\UserGroup;
use Statamic\Forms\Form as FormModel;
use Statamic\Search\IndexManager;
use Statamic\Search\Search;
use Statamic\Sites\Site as SiteModel;
use Statamic\Structures\Nav as NavModel;
use Statamic\Taxonomies\Taxonomy as TaxonomyModel;

function projectCollection(string $handle): CollectionModel
{
    return Collection::make($handle);
}

function projectEntry(string $id, CollectionModel $collection): EntryModel
{
    $entry = Entry::make();
    if (! $entry instanceof EntryModel) {
        throw new RuntimeException('Statamic must create a concrete entry model.');
    }

    $entry->id($id);
    $entry->collection($collection);

    return $entry;
}

function projectForm(string $handle): FormModel
{
    $form = Form::make($handle);
    if (! $form instanceof FormModel) {
        throw new RuntimeException('Statamic must create a concrete form model.');
    }

    return $form;
}

function projectTaxonomy(string $handle): TaxonomyModel
{
    $taxonomy = Taxonomy::make($handle);
    if (! $taxonomy instanceof TaxonomyModel) {
        throw new RuntimeException('Statamic must create a concrete taxonomy model.');
    }

    return $taxonomy;
}

function projectNav(string $handle): NavModel
{
    $nav = Nav::make($handle);
    if (! $nav instanceof NavModel) {
        throw new RuntimeException('Statamic must create a concrete navigation model.');
    }

    return $nav;
}

function projectSiteHandle(): string
{
    $site = Site::default();
    if (! $site instanceof SiteModel || ! is_string($site->handle())) {
        throw new RuntimeException('Statamic must provide a default site handle.');
    }

    return $site->handle();
}

function projectUserGroup(string $handle): UserGroupContract
{
    $group = UserGroup::make();
    $group->handle($handle);
    $group->title('Editors');

    return $group;
}

function projectRole(string $handle): RoleContract
{
    $role = Role::make();
    $role->handle($handle);
    $role->title('Author');

    return $role;
}

function projectFixturePath(string $path): string
{
    $resolved = realpath($path);
    if (! is_string($resolved)) {
        throw new RuntimeException("Fixture path [{$path}] must exist.");
    }

    return $resolved;
}

beforeEach(function (): void {
    View::addLocation(__DIR__.'/../Fixtures/project-views');
});

it('resolves exact, underscored, and partials-directory views without executing them', function (): void {
    expect(lintStatamic('statamic-partial-exists', '<s:partial src="exact"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="blog/card"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial:root-card/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial:nested/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial:missing/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="missing"/>'))->toHaveCount(1);
});

it('does not retain deleted partials from the shared Laravel view finder', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sheath-statamic-'.bin2hex(random_bytes(8));
    $view = $directory.DIRECTORY_SEPARATOR.'deletion-probe.blade.php';
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Temporary view directory could not be created.');
    }

    try {
        file_put_contents($view, 'temporary partial');
        View::addLocation($directory);

        expect(lintStatamic('statamic-partial-exists', '<s:partial src="deletion-probe"/>'))->toHaveCount(0);

        unlink($view);

        expect(lintStatamic('statamic-partial-exists', '<s:partial src="deletion-probe"/>'))->toHaveCount(1);
    } finally {
        if (is_file($view)) {
            unlink($view);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('skips intentional and runtime-dependent partial targets', function (): void {
    expect(lintStatamic('statamic-partial-exists', '<s:partial/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial:exists src="missing"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial:if_exists src="missing"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial :src="$partial"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="missing" :when="$condition"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="missing" {{ $attributes }}/>'))->toHaveCount(0);
});

it('evaluates static partial conditions and stands down only for unknown execution', function (): void {
    expect(lintStatamic('statamic-partial-exists', '<s:partial src="missing" when="true"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="missing" when="false"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="missing" unless="false"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="missing" unless="true"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="missing" :when="true"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="missing" :when="false"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', "<s:partial src=\"missing\" :when=\"'false'\"/>"))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="missing" :when="$condition"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', "{{ Statamic::tag('partial')->src('missing')->when(true) }}"))->toHaveCount(1)
        ->and(lintStatamic('statamic-partial-exists', "{{ Statamic::tag('partial')->src('missing')->when('false') }}"))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', "{{ Statamic::tag('partial')->src('missing')->unless('false') }}"))->toHaveCount(1)
        ->and(lintStatamic('statamic-partial-exists', "@tags(['card' => ['partial' => ['src' => 'missing', 'when' => 'false']]])"))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', "@tags(['card' => ['partial' => ['src' => 'missing', 'unless' => 'false']]])"))->toHaveCount(1);
});

it('evaluates bare and bound PHP literals like the compiled component attributes', function (): void {
    expect(lintStatamic('statamic-partial-exists', '<s:partial src="missing" when/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="missing" :when="0x1"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="missing" :when="0o0"/>'))->toHaveCount(0);
});

it('uses the final duplicate component partial source like Statamic compiled PHP', function (): void {
    expect(lintStatamic('statamic-partial-exists', '<s:partial src="missing" src="exact"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial src="exact" src="missing"/>'))->toHaveCount(1);
});

it('uses the compiler-injected partial suffix instead of an explicit component source', function (): void {
    expect(lintStatamic('statamic-partial-exists', '<s:partial:root-card src="missing"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial:missing src="root-card"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-partial-exists', '<s:partial:root-card :src="$partial"/>'))->toHaveCount(0);
});

it('checks partial views across conditional component, fluent, and TagsDirective invocations', function (): void {
    expect(lintStatamic(
        'statamic-partial-exists',
        '<s:partial @if($x) src="root-card" @else src="missing" @endif/>',
    ))->toHaveCount(1)
        ->and(lintStatamic(
            'statamic-partial-exists',
            '<s:partial @if($x) src="root-card" @else src="root-card" @endif/>',
        ))->toHaveCount(0)
        ->and(lintStatamic(
            'statamic-partial-exists',
            "{{ Statamic::tag('partial:missing') }}",
        ))->toHaveCount(1)
        ->and(lintStatamic(
            'statamic-partial-exists',
            "{{ Statamic::tag('partial')->src('root-card') }}",
        ))->toHaveCount(0)
        ->and(lintStatamic(
            'statamic-partial-exists',
            "@tags(['card' => ['partial' => ['src' => 'missing']]])",
        ))->toHaveCount(1)
        ->and(lintStatamic(
            'statamic-partial-exists',
            "@tags(['card' => ['partial' => ['src' => \$partial]]])",
        ))->toHaveCount(0);
});

it('checks literal handles against real project repositories and registries', function (): void {
    projectCollection('catalog_articles')->save();
    projectTaxonomy('catalog_tags')->save();
    projectForm('catalog_contact')->save();
    projectNav('catalog_main')->save();
    Route::get('/catalog-probe', static fn (): string => 'ok')->name('catalog.probe');
    AssetContainer::make('catalog_images')->save();
    projectUserGroup('catalog_editors')->save();
    projectRole('catalog_author')->save();
    Permission::register('catalog permission');
    config()->set('statamic.oauth.providers', ['catalog_oauth' => 'Catalog OAuth']);
    config()->set('statamic.search.indexes.catalog_search', [
        'driver' => 'local',
        'searchables' => ['collection:catalog_articles'],
        'fields' => ['title'],
    ]);
    app()->forgetInstance(IndexManager::class);
    app()->forgetInstance(Search::class);
    $site = projectSiteHandle();

    $sources = [
        "<s:get_site:{$site}/>",
        '<s:form:catalog_contact/>',
        '<s:form:create handle="catalog_contact"/>',
        '<s:taxonomy:catalog_tags/>',
        '<s:taxonomy from="catalog_tags"/>',
        '<s:nav:catalog_main></s:nav:catalog_main>',
        '<s:structure:catalog_main></s:structure:catalog_main>',
        '<s:nav:collection:catalog_articles></s:nav:collection:catalog_articles>',
        '<s:dictionary:fixture_dictionary/>',
        '<s:collection:catalog_articles/>',
        '<s:mount_url:catalog_articles/>',
        '<s:assets container="catalog_images"/>',
        '<s:assets collection="catalog_articles"/>',
        '<s:taxonomy from="catalog_tags" collection="catalog_articles"/>',
        "<s:locales:{$site}/>",
        '<s:oauth:catalog_oauth/>',
        '<s:search:results index="catalog_search" for="term"/>',
        '<s:user_groups handle="catalog_editors"/>',
        '<s:user_roles handle="catalog_author"/>',
        '<s:can permission="catalog permission"/>',
        '<s:in group="catalog_editors"/>',
        '<s:is role="catalog_author"/>',
        '<s:redirect route="catalog.probe"/>',
        '<s:route:catalog.probe/>',
    ];

    $failures = [];
    foreach ($sources as $source) {
        $count = count(lintStatamic('statamic-resource-exists', $source));
        if ($count !== 0) {
            $failures[$source] = $count;
        }
    }

    expect($failures)->toBe([]);
});

it('reports only literal resource handles that are proven absent', function (string $source, int $count): void {
    expect(lintStatamic('statamic-resource-exists', $source))->toHaveCount($count);
})->with([
    'site suffix' => ['<s:get_site:missing_site/>', 1],
    'site parameter' => ['<s:get_site handle="missing_site"/>', 1],
    'site handle is not pipe-delimited' => ['<s:get_site handle="missing_one|missing_two"/>', 1],
    'form suffix' => ['<s:form:missing_form/>', 1],
    'form method parameter' => ['<s:form:create handle="missing_form"/>', 1],
    'form handle is not pipe-delimited' => ['<s:form:create handle="missing_one|missing_two"/>', 1],
    'bare form may consume context' => ['<s:form/>', 0],
    'taxonomy suffix' => ['<s:taxonomy:missing_taxonomy/>', 1],
    'navigation suffix' => ['<s:nav:missing_navigation></s:nav:missing_navigation>', 1],
    'self-closing navigation is compiler-empty' => ['<s:nav:missing_navigation/>', 0],
    'structure collection suffix' => ['<s:structure:collection:missing_collection></s:structure:collection:missing_collection>', 1],
    'dictionary suffix' => ['<s:dictionary:missing_dictionary/>', 1],
    'dictionary handle is not pipe-delimited' => ['<s:dictionary handle="missing_one|missing_two"/>', 1],
    'collection suffix' => ['<s:collection:missing_collection/>', 1],
    'collection parameter' => ['<s:collection from="missing_collection"/>', 1],
    'collection wildcard' => ['<s:collection from="*"/>', 0],
    'mount URL collection' => ['<s:mount_url:missing_collection/>', 1],
    'mount URL site' => ['<s:mount_url:catalog_articles site="missing_site"/>', 1],
    'asset container' => ['<s:assets container="missing_container"/>', 1],
    'asset collection' => ['<s:assets collection="missing_collection"/>', 1],
    'asset collection is not pipe-delimited' => ['<s:assets collection="missing_one|missing_two"/>', 1],
    'self-closing children is compiler-empty' => ['<s:children collection="missing_collection"/>', 0],
    'paired children collection' => ['<s:children collection="missing_collection"></s:children>', 1],
    'taxonomy wildcard' => ['<s:taxonomy from="*"/>', 0],
    'taxonomy collection filter' => ['<s:taxonomy from="missing_taxonomy" collection="missing_collection"/>', 2],
    'taxonomy site filter' => ['<s:taxonomy from="catalog_tags" site="missing_site"/>', 1],
    'collection site filter' => ['<s:collection:catalog_articles site="missing_site"/>', 1],
    'get content site filter' => ['<s:get_content from="entry-id" locale="missing_site"/>', 1],
    'self-closing children site filter is compiler-empty' => ['<s:children collection="catalog_articles" site="missing_site"/>', 0],
    'paired children site filter' => ['<s:children collection="catalog_articles" site="missing_site"></s:children>', 1],
    'navigation site filter' => ['<s:nav:catalog_main site="missing_site"></s:nav:catalog_main>', 1],
    'structure site filter' => ['<s:structure for="collection::catalog_articles" site="missing_site"></s:structure>', 1],
    'path target site' => ['<s:path src="docs" in="missing_site"/>', 1],
    'locale site suffix' => ['<s:locales:missing_site/>', 1],
    'OAuth provider suffix' => ['<s:oauth:missing_provider/>', 1],
    'OAuth provider parameter' => ['<s:oauth:login_url provider="missing_provider"/>', 1],
    'search index' => ['<s:search:results index="missing_index" for="term"/>', 1],
    'user group' => ['<s:user_groups handle="missing_group"/>', 1],
    'user role' => ['<s:user_roles handle="missing_role"/>', 1],
    'permission' => ['<s:can permission="missing permission"/>', 1],
    'pipe-delimited permissions' => ['<s:can permission="missing one|missing two"/>', 2],
    'permission suffix' => ['<s:can:missing_permission/>', 1],
    'group check' => ['<s:in group="missing_group"/>', 1],
    'role check' => ['<s:is role="missing_role"/>', 1],
    'pipe-delimited group check' => ['<s:in group="missing_one|missing_two"/>', 2],
    'pipe-delimited role check' => ['<s:is role="missing_one|missing_two"/>', 2],
    'redirect route' => ['<s:redirect route="missing.route"/>', 1],
    'named route suffix' => ['<s:route:missing.route/>', 1],
    'route wildcard is still a literal name' => ['<s:route name="*"/>', 1],
    'dynamic site' => ['<s:get_site :handle="$site"/>', 0],
    'dynamic collection' => ['<s:collection :from="$collection"/>', 0],
    'opaque parameters' => ['<s:collection from="missing_collection" {{ $attributes }}/>', 0],
    'form fields consume context' => ['<s:form:fields/>', 0],
    'nav breadcrumbs does not resolve a nav' => ['<s:nav:breadcrumbs/>', 0],
]);

it('reports each absent literal in a pipe-delimited resource list', function (): void {
    projectCollection('catalog_known')->save();

    expect(lintStatamic(
        'statamic-resource-exists',
        '<s:collection from="catalog_known|missing_one|missing_two"/>',
    ))->toHaveCount(2);
});

it('checks every explicit conditional resource path without guessing opaque providers', function (): void {
    projectCollection('conditional_known')->save();

    expect(lintStatamic(
        'statamic-resource-exists',
        '<s:collection @if($condition) from="conditional_known" @else from="conditional_missing" @endif/>',
    ))->toHaveCount(1)
        ->and(lintStatamic(
            'statamic-resource-exists',
            '<s:collection @if($condition) from="conditional_known" @else from="conditional_known" @endif/>',
        ))->toHaveCount(0)
        ->and(lintStatamic(
            'statamic-resource-exists',
            '<s:collection from="conditional_missing" {{ $attributes }}/>',
        ))->toHaveCount(0);
});

it('checks literal project resources across fluent and TagsDirective invocations', function (): void {
    projectCollection('invocation_known')->save();

    expect(lintStatamic(
        'statamic-resource-exists',
        "{{ Statamic::tag('collection:missing_collection') }}",
    ))->toHaveCount(1)
        ->and(lintStatamic(
            'statamic-resource-exists',
            "{{ Statamic::tag('collection')->from('missing_collection') }}",
        ))->toHaveCount(1)
        ->and(lintStatamic(
            'statamic-resource-exists',
            "{{ Statamic::tag('collection')->from('invocation_known') }}",
        ))->toHaveCount(0)
        ->and(lintStatamic(
            'statamic-resource-exists',
            "{{ Statamic::tag('collection')->from(\$collection) }}",
        ))->toHaveCount(0)
        ->and(lintStatamic(
            'statamic-resource-exists',
            "@tags(['items' => ['collection' => ['from' => 'missing_collection']]])",
        ))->toHaveCount(1)
        ->and(lintStatamic(
            'statamic-resource-exists',
            "@tags(['items' => ['collection' => ['from' => 'invocation_known']]])",
        ))->toHaveCount(0)
        ->and(lintStatamic(
            'statamic-resource-exists',
            "@tags(['site' => ['get_site' => ['handle' => 'missing_site']]])",
        ))->toHaveCount(1);
});

it('uses the literal current entry to prove collection navigation compatibility', function (): void {
    $flatCollection = projectCollection('flat_catalog');
    $flatCollection->dated(false);
    $flatCollection->sortField('title');
    $flatEntry = projectEntry('flat-entry', $flatCollection);

    $datedCollection = projectCollection('dated_catalog');
    $datedCollection->dated(true);
    $datedEntry = projectEntry('dated-entry', $datedCollection);

    $orderedCollection = projectCollection('ordered_catalog');
    $orderedCollection->dated(false);
    $orderedCollection->sortField('order');
    $orderedEntry = projectEntry('ordered-entry', $orderedCollection);
    $orderedEntry->data(['order' => 1]);
    $unorderedEntry = projectEntry('unordered-entry', $orderedCollection);

    Entry::shouldReceive('find')->with('flat-entry')->andReturn($flatEntry);
    Entry::shouldReceive('find')->with('dated-entry')->andReturn($datedEntry);
    Entry::shouldReceive('find')->with('ordered-entry')->andReturn($orderedEntry);
    Entry::shouldReceive('find')->with('unordered-entry')->andReturn($unorderedEntry);
    Entry::shouldReceive('find')->with('missing-entry')->andReturnNull();
    Collection::shouldReceive('findByHandle')->with('flat_catalog')->andReturn($flatCollection);
    Collection::shouldReceive('findByHandle')->with('dated_catalog')->andReturn($datedCollection);
    Collection::shouldReceive('findByHandle')->with('ordered_catalog')->andReturn($orderedCollection);
    Collection::shouldReceive('getComputedCallbacks')->andReturn(collect());

    expect(lintStatamic('statamic-collection-method-compatibility', '<s:collection:next current="flat-entry"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-collection-method-compatibility', '<s:collection:older current="flat-entry"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-collection-method-compatibility', '<s:collection:next current="dated-entry"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-collection-method-compatibility', '<s:collection:next current="dated-entry" order_by="title"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-collection-method-compatibility', '<s:collection:next current="ordered-entry"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-collection-method-compatibility', '<s:collection:previous current="unordered-entry"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-collection-method-compatibility', '<s:collection:next current="missing-entry"/>'))->toHaveCount(1);
});

it('skips collection navigation that depends on render context or dynamic values', function (): void {
    expect(lintStatamic('statamic-collection-method-compatibility', '<s:collection:next in="pages"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-collection-method-compatibility', '<s:collection:next :current="$id"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-collection-method-compatibility', '<s:collection:next current="id" :sort="$sort"/>'))->toHaveCount(0);
});

it('reports parameters the Statamic collection navigation runtime always rejects', function (string $source, int $count): void {
    expect(lintStatamic('statamic-collection-method-compatibility', $source))->toHaveCount($count);
})->with([
    'next paginate' => ['<s:collection:next paginate="10"/>', 1],
    'previous offset' => ['<s:collection:previous offset="1"/>', 1],
    'older paginate' => ['<s:collection:older paginate/>', 1],
    'newer offset' => ['<s:collection:newer offset="0"/>', 1],
    'multiple collections are overwritten by the current entry collection' => ['<s:collection:next from="articles|news"/>', 0],
    'optional incompatible parameter' => ['<s:collection:next @if($paged) paginate="10" @endif/>', 1],
    'exhaustive compatible paths' => ['<s:collection:next @if($x) limit="1" @else limit="2" @endif/>', 0],
    'opaque parameters are skipped' => ['<s:collection:next {{ $attributes }}/> ', 0],
    'fluent paginate' => ["{{ Statamic::tag('collection:next')->paginate('10') }}", 1],
    'fluent collections are overwritten by the current entry collection' => ["{{ Statamic::tag('collection:previous')->from('one|two') }}", 0],
    'TagsDirective offset' => ["@tags(['next' => ['collection:next' => ['offset' => 1]]])", 1],
    'TagsDirective compatible' => ["@tags(['next' => ['collection:next' => ['limit' => 1]]])", 0],
]);

it('invalidates project cache context for view creation but not content-only edits', function (): void {
    $catalog = app(ProjectCatalog::class);
    $directory = __DIR__.'/../Fixtures/alternate-views';
    $existing = $directory.'/cache-probe.blade.php';
    $created = $directory.'/created-cache-probe.blade.php';
    $original = file_get_contents($existing);
    View::addLocation($directory);

    if (! is_string($original)) {
        throw new RuntimeException('Existing view fixture could not be read.');
    }
    $before = $catalog->cacheContext();

    try {
        file_put_contents($existing, $original."\ncontent-only edit");
        $contentChanged = $catalog->cacheContext();
        file_put_contents($created, 'new view');
        $viewCreated = $catalog->cacheContext();
        $createdPath = realpath($created);
        if (! is_string($createdPath)) {
            throw new RuntimeException('Created view path could not be resolved.');
        }

        expect($contentChanged)->toBe($before)
            ->and($viewCreated)->not->toBe($contentChanged)
            ->and($viewCreated['views'])->toHaveKey($createdPath);
    } finally {
        file_put_contents($existing, $original);
        if (is_file($created)) {
            unlink($created);
        }
    }
});

it('recomputes project cache context for repeated commands in one application', function (): void {
    $catalog = app(ProjectCatalog::class);
    $before = $catalog->cacheContext();

    Route::get('/same-instance-cache-probe', static fn (): string => 'ok')->name('same.instance.cache.probe');
    View::addLocation(__DIR__.'/../Fixtures/alternate-views');
    $after = $catalog->cacheContext();

    expect($before)->not->toBe($after)
        ->and($catalog->resourceExists(ResourceType::Route, 'same.instance.cache.probe'))->toBeTrue()
        ->and($after['views'])->toHaveKey(projectFixturePath(__DIR__.'/../Fixtures/alternate-views/cache-probe.blade.php'));
});

it('invalidates only the project cache dependency that changed', function (): void {
    $catalog = app(ProjectCatalog::class);
    $viewsBefore = $catalog->viewCacheContext();
    $resourcesBefore = $catalog->resourceCacheContext();
    $collectionsBefore = $catalog->collectionCacheContext();

    Route::get('/isolated-cache-probe', static fn (): string => 'ok')->name('isolated.cache.probe');
    $resourcesAfterRoute = $catalog->resourceCacheContext();

    expect($resourcesAfterRoute)->not->toBe($resourcesBefore)
        ->and($catalog->viewCacheContext())->toBe($viewsBefore)
        ->and($catalog->collectionCacheContext())->toBe($collectionsBefore);

    View::addLocation(__DIR__.'/../Fixtures/alternate-views');

    expect($catalog->viewCacheContext())->not->toBe($viewsBefore)
        ->and($catalog->resourceCacheContext())->toBe($resourcesAfterRoute)
        ->and($catalog->collectionCacheContext())->toBe($collectionsBefore);
});

it('scopes project rule cache contexts to the state each rule reads', function (): void {
    $projectContext = static function (ProjectAwareRule $rule): array {
        $context = $rule->cacheContext([]);
        if (! is_array($context) || ! is_array($context['project'] ?? null)) {
            throw new RuntimeException('Project-aware rules must provide a project cache context.');
        }

        return $context['project'];
    };

    $partial = app(PartialExistsRule::class);
    $resources = app(ResourceExistsRule::class);
    $collections = app(CollectionMethodCompatibilityRule::class);

    expect(array_keys($projectContext($partial)))->toBe(['schema', 'views'])
        ->and(array_keys($projectContext($resources)))->toBe(['schema', 'resources'])
        ->and(array_keys($projectContext($collections)))->toBe(['schema', 'collections', 'entries'])
        ->and($partial->cacheContextGroup([]))->toBe('fortephp/sheath-statamic:project:views')
        ->and($resources->cacheContextGroup([]))->toBe('fortephp/sheath-statamic:project:resources')
        ->and($collections->cacheContextGroup([]))->toBe('fortephp/sheath-statamic:project:collections');
});
