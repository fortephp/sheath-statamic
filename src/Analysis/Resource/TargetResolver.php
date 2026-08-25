<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Resource;

use Forte\Sheath\Statamic\Analysis\Parameter\StaticSource;
use Forte\Sheath\Statamic\Analysis\Parameter\StaticValue;

final class TargetResolver
{
    /** @var list<string> */
    private const FORM_LOOKUP_METHODS = ['create', 'errors', 'success', 'submission', 'submissions'];

    /** @return list<Target>|null */
    public function resolve(
        string $handle,
        ?string $method,
        StaticSource $parameters,
        bool $resolvesStructuredContent,
    ): ?array {
        return match ($handle) {
            'assets' => $this->assetTargets($method, $parameters),
            'can' => $this->methodOrParameterTargets($method, $parameters, ['permission', 'do'], ResourceType::Permission),
            'children' => $this->withSiteTargets(
                $this->parameterTargets($parameters, ['collection'], ResourceType::Collection, false),
                $parameters,
            ),
            'get_content' => $this->parameterTargets($parameters, ['site', 'locale'], ResourceType::Site, false),
            'get_site' => $this->siteTargets($method, $parameters),
            'in' => $this->methodOrParameterTargets($method, $parameters, ['group', 'groups'], ResourceType::UserGroup),
            'is' => $this->methodOrParameterTargets($method, $parameters, ['role', 'roles'], ResourceType::UserRole),
            'form' => $this->formTargets($method, $parameters),
            'taxonomy' => $this->taxonomyTargets($method, $parameters),
            'nav' => $this->structureTargets($method, $parameters, true, $resolvesStructuredContent),
            'structure' => $this->structureTargets($method, $parameters, false, $resolvesStructuredContent),
            'dictionary' => $this->dictionaryTargets($method, $parameters),
            'collection' => $this->collectionTargets($method, $parameters),
            'locales' => $this->localeTargets($method),
            'mount_url' => $this->withSiteTargets(
                $this->methodOrParameterTargets($method, $parameters, ['handle'], ResourceType::Collection, false),
                $parameters,
                ['site'],
            ),
            'oauth' => $this->oauthTargets($method, $parameters),
            'path' => $this->parameterTargets($parameters, ['in'], ResourceType::Site, false),
            'redirect' => $this->parameterTargets($parameters, ['route'], ResourceType::Route, false),
            'route' => $this->routeTargets($method, $parameters),
            'search' => $this->searchTargets($method, $parameters),
            'user_groups' => $this->parameterTargets($parameters, ['handle'], ResourceType::UserGroup),
            'user_roles' => $this->parameterTargets($parameters, ['handle'], ResourceType::UserRole),
            default => [],
        };
    }

    /** @return list<Target>|null */
    private function assetTargets(?string $method, StaticSource $parameters): ?array
    {
        if (! $this->isIndexMethod($method)) {
            return [];
        }

        $collection = $parameters->firstStaticParameter(['collection']);
        if ($collection->present) {
            if (! $collection->known || $collection->value === null) {
                return null;
            }

            if (trim($collection->value) !== '' && trim($collection->value) !== '0') {
                return $this->targetsFromValue($collection, ResourceType::Collection, false);
            }
        }

        return $this->parameterTargets($parameters, ['container', 'handle', 'id'], ResourceType::AssetContainer, false);
    }

    /** @return list<Target>|null */
    private function siteTargets(?string $method, StaticSource $parameters): ?array
    {
        if ($this->isIndexMethod($method)) {
            return $this->parameterTargets($parameters, ['handle'], ResourceType::Site, false);
        }

        return [new Target(ResourceType::Site, explode(':', $method)[0])];
    }

    /** @return list<Target>|null */
    private function formTargets(?string $method, StaticSource $parameters): ?array
    {
        if (in_array($method, ['set', 'fields'], true)) {
            return [];
        }

        $parameter = $parameters->firstStaticParameter(['handle', 'is', 'in', 'form', 'formset']);
        if ($parameter->present) {
            return $this->targetsFromValue($parameter, ResourceType::Form, false);
        }

        if ($this->isIndexMethod($method) || in_array($method, self::FORM_LOOKUP_METHODS, true)) {
            return [];
        }

        return [new Target(ResourceType::Form, $method)];
    }

    /** @return list<Target>|null */
    private function taxonomyTargets(?string $method, StaticSource $parameters): ?array
    {
        if ($method !== null && ! in_array($method, ['index', 'count'], true)) {
            return $method === '*' ? [] : [new Target(ResourceType::Taxonomy, $method)];
        }

        $taxonomies = $this->parameterTargets(
            $parameters,
            ['from', 'in', 'folder', 'use', 'taxonomy'],
            ResourceType::Taxonomy,
            allowWildcard: true,
        );
        $collections = $this->parameterTargets($parameters, ['collection', 'collections'], ResourceType::Collection);
        $sites = $this->parameterTargets(
            $parameters,
            ['site', 'locale'],
            ResourceType::Site,
            false,
            allowWildcard: true,
        );

        return $taxonomies === null || $collections === null || $sites === null
            ? null
            : [...$taxonomies, ...$collections, ...$sites];
    }

    /** @return list<Target>|null */
    private function structureTargets(
        ?string $method,
        StaticSource $parameters,
        bool $nav,
        bool $resolvesStructuredContent,
    ): ?array {
        if (! $resolvesStructuredContent || ($nav && $method === 'breadcrumbs')) {
            return [];
        }

        if ($this->isIndexMethod($method)) {
            $parameter = $parameters->firstStaticParameter([$nav ? 'handle' : 'for']);
            if (! $parameter->present) {
                return $nav ? [new Target(ResourceType::Collection, 'pages')] : [];
            }

            return $this->withSiteTargets($this->structureValueTargets($parameter), $parameters);
        }

        return $this->withSiteTargets(
            $this->structureValueTargets(StaticValue::literal(str_replace(':', '::', $method))),
            $parameters,
        );
    }

    /** @return list<Target>|null */
    private function dictionaryTargets(?string $method, StaticSource $parameters): ?array
    {
        if ($this->isIndexMethod($method)) {
            return $this->parameterTargets($parameters, ['handle'], ResourceType::Dictionary, false);
        }

        return [new Target(ResourceType::Dictionary, $method)];
    }

    /** @return list<Target>|null */
    private function collectionTargets(?string $method, StaticSource $parameters): ?array
    {
        if ($method !== null
            && ! in_array($method, ['index', 'count', 'next', 'previous', 'older', 'newer'], true)) {
            return $this->withSiteTargets(
                $method === '*' ? [] : [new Target(ResourceType::Collection, $method)],
                $parameters,
                allowWildcardSite: true,
            );
        }

        if (in_array($method, ['next', 'previous', 'older', 'newer'], true)) {
            return $this->parameterTargets($parameters, ['site', 'locale'], ResourceType::Site, false);
        }

        return $this->withSiteTargets(
            $this->parameterTargets(
                $parameters,
                ['from', 'in', 'folder', 'use', 'collection'],
                ResourceType::Collection,
                allowWildcard: true,
            ),
            $parameters,
            allowWildcardSite: true,
        );
    }

    /** @return list<Target>|null */
    private function routeTargets(?string $method, StaticSource $parameters): ?array
    {
        if ($this->isIndexMethod($method)) {
            return $this->parameterTargets($parameters, ['name'], ResourceType::Route, false);
        }

        return [new Target(ResourceType::Route, $method)];
    }

    /** @return list<Target> */
    private function localeTargets(?string $method): array
    {
        if ($method === null || in_array($method, ['index', 'count'], true)) {
            return [];
        }

        return [new Target(ResourceType::Site, $method)];
    }

    /** @return list<Target>|null */
    private function oauthTargets(?string $method, StaticSource $parameters): ?array
    {
        if ($this->isIndexMethod($method)) {
            return [];
        }

        if (in_array($method, ['login_url', 'disconnect_form'], true)) {
            return $this->parameterTargets($parameters, ['provider', 'for'], ResourceType::OAuthProvider, false);
        }

        return [new Target(ResourceType::OAuthProvider, $method)];
    }

    /** @return list<Target>|null */
    private function searchTargets(?string $method, StaticSource $parameters): ?array
    {
        if ($method !== 'results') {
            return [];
        }

        return $this->parameterTargets($parameters, ['index'], ResourceType::SearchIndex, false);
    }

    /**
     * @param  list<string>  $aliases
     * @return list<Target>|null
     */
    private function methodOrParameterTargets(
        ?string $method,
        StaticSource $parameters,
        array $aliases,
        ResourceType $type,
        bool $pipeDelimited = true,
    ): ?array {
        if (! $this->isIndexMethod($method)) {
            return [new Target($type, $method)];
        }

        return $this->parameterTargets($parameters, $aliases, $type, $pipeDelimited);
    }

    /**
     * @param  list<string>  $aliases
     * @return list<Target>|null
     */
    private function parameterTargets(
        StaticSource $parameters,
        array $aliases,
        ResourceType $type,
        bool $pipeDelimited = true,
        bool $allowWildcard = false,
    ): ?array {
        return $this->targetsFromValue(
            $parameters->firstStaticParameter($aliases),
            $type,
            $pipeDelimited,
            $allowWildcard,
        );
    }

    /** @return list<Target>|null */
    private function targetsFromValue(
        StaticValue $parameter,
        ResourceType $type,
        bool $pipeDelimited = true,
        bool $allowWildcard = false,
    ): ?array {
        if (! $parameter->present) {
            return [];
        }

        if (! $parameter->known || $parameter->value === null) {
            return null;
        }

        $handles = $pipeDelimited ? explode('|', $parameter->value) : [$parameter->value];
        if ($allowWildcard) {
            $handles = array_values(array_filter($handles, static fn (string $handle): bool => $handle !== '*'));
        }

        return array_map(static fn (string $handle): Target => new Target($type, $handle), $handles);
    }

    /** @return list<Target>|null */
    private function structureValueTargets(StaticValue $parameter): ?array
    {
        $targets = $this->targetsFromValue($parameter, ResourceType::Navigation, false);
        if ($targets === null || $targets === []) {
            return $targets;
        }

        $handle = $targets[0]->handle;
        if (str_starts_with($handle, 'collection::')) {
            return [new Target(ResourceType::Collection, substr($handle, 12))];
        }

        return $targets;
    }

    /**
     * @param  list<Target>|null  $targets
     * @param  list<string>  $aliases
     * @return list<Target>|null
     */
    private function withSiteTargets(
        ?array $targets,
        StaticSource $parameters,
        array $aliases = ['site', 'locale'],
        bool $allowWildcardSite = false,
    ): ?array {
        $sites = $this->parameterTargets(
            $parameters,
            $aliases,
            ResourceType::Site,
            false,
            $allowWildcardSite,
        );

        return $targets === null || $sites === null ? null : [...$targets, ...$sites];
    }

    /** @phpstan-assert-if-false string $method */
    private function isIndexMethod(?string $method): bool
    {
        return $method === null || $method === 'index';
    }
}
