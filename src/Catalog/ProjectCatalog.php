<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Catalog;

use FilesystemIterator;
use Forte\Sheath\Statamic\Analysis\Resource\ResourceType;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Statamic\Entries\Collection as CollectionModel;
use Statamic\Entries\Entry as EntryModel;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\Nav;
use Statamic\Facades\OAuth;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\Search;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\UserGroup;
use Throwable;

final class ProjectCatalog
{
    public function partialExists(string $partial): ?bool
    {
        try {
            $partial = str_replace('/', '.', $partial);
            $bits = explode('.', $partial);
            $last = array_pop($bits);
            $parent = implode('.', $bits);
            $underscored = ($parent === '' ? '' : $parent.'.').'_'.$last;

            $views = app('view');
            if (! $views instanceof ViewFactory) {
                return null;
            }
            foreach ([$underscored, 'partials.'.$partial, 'partials.'.$underscored, $partial] as $candidate) {
                if ($this->viewExists($views, $candidate)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return null;
        }
    }

    private function viewExists(ViewFactory $views, string $candidate): bool
    {
        if (! $views->exists($candidate)) {
            return false;
        }

        $finder = $views->getFinder();
        if (! $finder instanceof FileViewFinder) {
            return true;
        }

        // FileViewFinder caches successful lookups indefinitely. Validate the
        // cached path so a deleted partial does not remain present when this
        // scoped catalog is reused by a long-lived lint process.
        return is_file($finder->find($candidate));
    }

    public function resourceExists(ResourceType $type, string $handle): ?bool
    {
        try {
            return match ($type) {
                ResourceType::Site => $this->hasHandle(Site::all(), $handle),
                ResourceType::Form => $this->hasHandle(Form::all(), $handle),
                ResourceType::Taxonomy => Taxonomy::findByHandle($handle) !== null,
                ResourceType::Navigation => Nav::findByHandle($handle) !== null,
                ResourceType::Dictionary => array_key_exists($handle, $this->dictionaryBindings()),
                ResourceType::Collection => Collection::findByHandle($handle) !== null,
                ResourceType::Route => in_array($handle, $this->routeNames(), true),
                ResourceType::AssetContainer => $this->hasHandle(AssetContainer::all(), $handle),
                ResourceType::OAuthProvider => $this->collectionHasKey(OAuth::providers(), $handle),
                ResourceType::Permission => $this->collectionHasKey(Permission::boot()->all(), $handle),
                ResourceType::SearchIndex => $this->searchIndexExists(Search::indexes(), $handle),
                ResourceType::UserGroup => UserGroup::find($handle) !== null,
                ResourceType::UserRole => Role::find($handle) !== null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    public function collectionMethodProblem(string $entryId, string $method, ?string $staticSort): string|false|null
    {
        try {
            $entry = Entry::find($entryId);
            if ($entry === null) {
                return "Entry `{$entryId}` does not exist. Use an existing entry for `current`.";
            }
            if (! $entry instanceof EntryModel) {
                return null;
            }

            $collection = $entry->collection();
            if ($collection === null) {
                return "Entry `{$entryId}` has no collection. Use an entry assigned to one.";
            }
            if (! $collection instanceof CollectionModel) {
                return null;
            }
            $collectionHandle = $collection->handle();
            $collectionSort = $collection->sortField();
            if (! is_string($collectionHandle) || ! is_string($collectionSort)) {
                return null;
            }

            $dated = (bool) $collection->dated();
            if (in_array($method, ['older', 'newer'], true) && ! $dated) {
                return "Collection `{$collectionHandle}` is not dated. `collection:{$method}` needs a dated collection.";
            }

            $sort = $staticSort ?? $collectionSort;
            $primarySort = explode(':', explode('|', $sort)[0])[0];

            if ($primarySort === 'order') {
                return $entry->order()
                    ? false
                    : "Entry `{$entryId}` has no manual order. `collection:{$method}` needs one.";
            }

            if ($dated && $primarySort === 'date') {
                return false;
            }

            return "Collection `{$collectionHandle}` is sorted by `{$primarySort}`. `collection:{$method}` needs `order` or `date`.";
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{schema: int, resources: array<string, mixed>, collections: mixed, entries: mixed, views: array<string, string>} */
    public function cacheContext(): array
    {
        return [
            'schema' => 2,
            'resources' => $this->resourceState(),
            'collections' => $this->safeState(fn (): array => $this->collectionState()),
            'entries' => $this->safeState(fn (): array => $this->entryState()),
            'views' => $this->viewState(),
        ];
    }

    /** @return array{schema: int, resources: array<string, mixed>} */
    public function resourceCacheContext(): array
    {
        return ['schema' => 1, 'resources' => $this->resourceState()];
    }

    /** @return array{schema: int, collections: mixed, entries: mixed} */
    public function collectionCacheContext(): array
    {
        return [
            'schema' => 1,
            'collections' => $this->safeState(fn (): array => $this->collectionState()),
            'entries' => $this->safeState(fn (): array => $this->entryState()),
        ];
    }

    /** @return array{schema: int, views: array<string, string>} */
    public function viewCacheContext(): array
    {
        return ['schema' => 1, 'views' => $this->viewState()];
    }

    /** @return array<string, mixed> */
    private function resourceState(): array
    {
        return [
            'sites' => $this->safeState(fn (): array => $this->handlesFromMixed(Site::all()) ?? []),
            'forms' => $this->safeState(fn (): array => $this->handlesFromMixed(Form::all()) ?? []),
            'taxonomies' => $this->safeState(fn (): array => $this->handles(Taxonomy::all())),
            'navigations' => $this->safeState(fn (): array => $this->handles(Nav::all())),
            'dictionaries' => $this->safeState(fn (): array => $this->dictionaryBindings()),
            'collections' => $this->safeState(fn (): array => $this->handles(Collection::all())),
            'routes' => $this->safeState(fn (): array => $this->routeNames()),
            'asset_containers' => $this->safeState(fn (): array => $this->handles(AssetContainer::all())),
            'oauth_providers' => $this->safeState(fn (): array => $this->collectionKeys(OAuth::providers()) ?? []),
            'permissions' => $this->safeState(fn (): array => $this->collectionKeys(Permission::boot()->all()) ?? []),
            'search_indexes' => $this->safeState(fn (): array => $this->searchIndexNames(Search::indexes()) ?? []),
            'user_groups' => $this->safeState(fn (): array => $this->handles(UserGroup::all())),
            'user_roles' => $this->safeState(fn (): array => $this->handles(Role::all())),
        ];
    }

    /** @return array<string, array{dated: bool, orderable: bool, sort: string}> */
    private function collectionState(): array
    {
        $collections = [];
        foreach (Collection::all() as $collection) {
            if (! $collection instanceof CollectionModel) {
                continue;
            }

            $handle = $collection->handle();
            $sort = $collection->sortField();
            if (! is_string($handle) || ! is_string($sort)) {
                continue;
            }

            $collections[$handle] = [
                'dated' => (bool) $collection->dated(),
                'orderable' => (bool) $collection->orderable(),
                'sort' => $sort,
            ];
        }
        ksort($collections);

        return $collections;
    }

    /** @return array<string, array{collection: string|null, order: mixed}> */
    private function entryState(): array
    {
        $entries = [];
        foreach (Entry::all() as $entry) {
            if (! $entry instanceof EntryModel || ! is_string($entry->id())) {
                continue;
            }

            $collection = $entry->collection();
            $collectionHandle = $collection instanceof CollectionModel && is_string($collection->handle())
                ? $collection->handle()
                : null;
            $entries[$entry->id()] = [
                'collection' => $collectionHandle,
                'order' => $entry->order(),
            ];
        }
        ksort($entries);

        return $entries;
    }

    private function safeState(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            return ['unavailable' => $exception::class];
        }
    }

    /** @return array<string, string> */
    private function viewState(): array
    {
        try {
            $views = app('view');
            if (! $views instanceof ViewFactory) {
                return ['factory' => get_debug_type($views)];
            }

            $finder = $views->getFinder();
            if (! $finder instanceof FileViewFinder) {
                return ['finder' => get_debug_type($finder)];
            }

            $directories = [];
            foreach ($finder->getPaths() as $path) {
                $directories[] = $path;
            }
            foreach ($finder->getHints() as $paths) {
                foreach ($paths as $path) {
                    if (is_string($path)) {
                        $directories[] = $path;
                    }
                }
            }

            $files = [];
            foreach (array_unique($directories) as $directory) {
                if (! is_dir($directory)) {
                    continue;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iterator as $file) {
                    if (! $file instanceof \SplFileInfo) {
                        continue;
                    }
                    if (! $file->isFile()) {
                        continue;
                    }

                    $path = $file->getPathname();
                    $files[$path] = 'present';
                }
            }

            ksort($files);

            return $files;
        } catch (Throwable $exception) {
            return ['unavailable' => $exception::class];
        }
    }

    /**
     * @param  iterable<mixed>  $resources
     * @return list<string>
     */
    private function handles(iterable $resources): array
    {
        $handles = [];
        foreach ($resources as $resource) {
            if (is_object($resource) && method_exists($resource, 'handle')) {
                $handle = $resource->handle();
                if (is_string($handle)) {
                    $handles[] = $handle;
                }
            }
        }
        sort($handles);

        return $handles;
    }

    /** @return list<string>|null */
    private function handlesFromMixed(mixed $resources): ?array
    {
        return is_iterable($resources) ? $this->handles($resources) : null;
    }

    private function hasHandle(mixed $resources, string $handle): ?bool
    {
        $handles = $this->handlesFromMixed($resources);

        return $handles === null ? null : in_array($handle, $handles, true);
    }

    private function collectionHasKey(mixed $values, string $key): ?bool
    {
        return $values instanceof SupportCollection ? $values->has($key) : null;
    }

    /** @return list<string>|null */
    private function collectionKeys(mixed $values): ?array
    {
        if (! $values instanceof SupportCollection) {
            return null;
        }

        $keys = [];
        foreach ($values->keys() as $key) {
            if (is_string($key)) {
                $keys[] = $key;
            }
        }
        sort($keys);

        return $keys;
    }

    private function searchIndexExists(mixed $indexes, string $handle): ?bool
    {
        $names = $this->searchIndexNames($indexes);

        return $names === null ? null : in_array($handle, $names, true);
    }

    /** @return list<string>|null */
    private function searchIndexNames(mixed $indexes): ?array
    {
        if (! $indexes instanceof SupportCollection) {
            return null;
        }

        $names = [];
        foreach ($indexes as $key => $index) {
            if (is_string($key)) {
                $names[] = $key;
            }
            if (is_object($index) && method_exists($index, 'name')) {
                $name = $index->name();
                if (is_string($name)) {
                    $names[] = $name;
                }
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** @return array<string, string> */
    private function dictionaryBindings(): array
    {
        $registry = app('statamic.dictionaries');
        if (! is_iterable($registry)) {
            return [];
        }

        $bindings = [];
        foreach ($registry as $handle => $binding) {
            if (is_string($handle)) {
                if (is_object($binding)) {
                    $bindings[$handle] = $binding::class;
                } elseif (is_string($binding)) {
                    $bindings[$handle] = $binding;
                }
            }
        }
        ksort($bindings);

        return $bindings;
    }

    private function router(): ?Router
    {
        $router = app('router');

        return $router instanceof Router ? $router : null;
    }

    /** @return list<string> */
    private function routeNames(): array
    {
        $names = [];
        foreach ($this->router()?->getRoutes()->getRoutes() ?? [] as $route) {
            $name = $route->getName();
            if (is_string($name)) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }
}
