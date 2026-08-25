<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Parameter;

final class Requirements
{
    private const INDEX_METHODS = [null, 'index'];

    /** @return array{name: string, aliases: list<string>, allowZero: bool, strategy?: 'any'}|null */
    public static function for(string $handle, ?string $method): ?array
    {
        foreach (self::definitions()[$handle] ?? [] as $definition) {
            if (! self::matchesMethod($definition, $method)) {
                continue;
            }

            $requirement = [
                'name' => $definition['name'],
                'aliases' => $definition['aliases'],
                'allowZero' => $definition['allowZero'],
            ];
            if ($definition['strategy'] === 'any') {
                $requirement['strategy'] = 'any';
            }

            return $requirement;
        }

        return null;
    }

    /**
     * @return array<string, list<array{
     *     name: string,
     *     aliases: list<string>,
     *     allowZero: bool,
     *     methods: list<string|null>|null,
     *     excludedMethods: list<string>,
     *     strategy: 'any'|null
     * }>>
     */
    private static function definitions(): array
    {
        /** @var array<string, list<array{name: string, aliases: list<string>, allowZero: bool, methods: list<string|null>|null, excludedMethods: list<string>, strategy: 'any'|null}>>|null $definitions */
        static $definitions = null;

        if ($definitions !== null) {
            return $definitions;
        }

        $keyValueStore = [
            self::definition('key', ['key'], allowZero: true, methods: ['value', 'has']),
            self::definition('keys', ['keys'], methods: ['forget']),
        ];
        $iterable = [self::definition('array', ['array'], allowZero: true)];
        $passkey = [self::definition('id', ['id'], methods: ['delete_passkey_form'])];
        $range = [self::definition('to', ['to', 'times'], allowZero: true)];
        $rotation = [self::definition('between', ['between'])];
        $translation = [self::definition('key', ['key'], allowZero: true)];

        return $definitions = [
            'can' => [self::definition('permission', ['permission', 'do'])],
            'cookie' => $keyValueStore,
            'session' => $keyValueStore,
            'asset' => [self::definition('url', ['url', 'src'])],
            'assets' => [self::definition(
                'container, collection, or path',
                ['container', 'handle', 'id', 'collection', 'path'],
                strategy: 'any',
            )],
            'collection' => [self::definition(
                'from',
                ['from', 'in', 'folder', 'use', 'collection'],
                methods: [null, 'index', 'count'],
            )],
            'dictionary' => [self::definition('handle', ['handle'])],
            'get_content' => [self::definition('from', ['from', 'id'])],
            'get_files' => [self::definition('in', ['in', 'from'])],
            'in' => [self::definition('group', ['group', 'groups'])],
            'is' => [self::definition('role', ['role', 'roles'])],
            'foreach' => $iterable,
            'iterate' => $iterable,
            'form' => [self::definition(
                'handle',
                ['handle', 'is', 'in', 'form', 'formset'],
                methods: [null, 'index', 'set'],
            )],
            'user' => $passkey,
            'member' => $passkey,
            'vite' => [self::definition('src', ['src'], methods: [null, 'index', 'asset', 'content'])],
            'get_site' => [self::definition('handle', ['handle'])],
            'query' => [self::definition('builder', ['builder'], allowZero: true)],
            'taxonomy' => [self::definition(
                'from',
                ['from', 'in', 'folder', 'use', 'taxonomy'],
                allowZero: true,
                methods: [null, 'index', 'count'],
            )],
            'glide' => [self::definition(
                'src',
                ['src', 'id', 'path'],
                methods: [null, 'index', 'data_url', 'data_uri', 'generate'],
            )],
            'installed' => [self::definition('package', ['package'])],
            'increment' => [self::definition('counter', ['counter'], allowZero: true, methods: ['reset'])],
            'mount_url' => [self::definition('handle', ['handle'])],
            'mix' => [self::definition('src', ['src', 'path'])],
            'oauth' => [self::definition('provider', ['provider', 'for'], methods: ['login_url', 'disconnect_form'])],
            'partial' => [self::definition('src', ['src'], methods: [null])],
            'redirect' => [self::definition('to', ['to', 'url', 'route'], strategy: 'any')],
            'loop' => $range,
            'range' => $range,
            'route' => [self::definition('name', ['name'])],
            'rotate' => $rotation,
            'switch' => $rotation,
            'structure' => [self::definition('for', ['for'])],
            'svg' => [self::definition('src', ['src'])],
            'theme' => [self::definition('src', ['src'], methods: null, excludedMethods: ['css', 'js'])],
            'trans' => $translation,
            'trans_choice' => $translation,
        ];
    }

    /**
     * @param  list<string>  $aliases
     * @param  list<string|null>|null  $methods
     * @param  list<string>  $excludedMethods
     * @param  'any'|null  $strategy
     * @return array{name: string, aliases: list<string>, allowZero: bool, methods: list<string|null>|null, excludedMethods: list<string>, strategy: 'any'|null}
     */
    private static function definition(
        string $name,
        array $aliases,
        bool $allowZero = false,
        ?array $methods = self::INDEX_METHODS,
        array $excludedMethods = [],
        ?string $strategy = null,
    ): array {
        return compact('name', 'aliases', 'allowZero', 'methods', 'excludedMethods', 'strategy');
    }

    /** @param array{methods: list<string|null>|null, excludedMethods: list<string>} $definition */
    private static function matchesMethod(array $definition, ?string $method): bool
    {
        return $definition['methods'] === null
            ? ! in_array($method, $definition['excludedMethods'], true)
            : in_array($method, $definition['methods'], true);
    }
}
