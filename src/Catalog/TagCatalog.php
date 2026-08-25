<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Catalog;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Statamic\Statamic;
use Statamic\Tags\Tags;
use Throwable;

final class TagCatalog
{
    /** @var array<string, class-string|object>|null */
    private ?array $bindings = null;

    /** @var array<string, array{class: string, signature: string}>|null */
    private ?array $cachedContext = null;

    /** @var array<string, mixed>|null */
    private ?array $cachedContextSnapshot = null;

    public function has(string $handle): bool
    {
        return $this->bindingFor($handle) !== null;
    }

    public function acceptsMethod(string $handle, ?string $method): ?bool
    {
        return $this->acceptsMethodForSurface($handle, $method, false);
    }

    public function acceptsComponentMethod(string $handle, ?string $method): ?bool
    {
        return $this->acceptsMethodForSurface($handle, $method, true);
    }

    private function acceptsMethodForSurface(string $handle, ?string $method, bool $component): ?bool
    {
        $binding = $this->bindingFor($handle);
        if ($binding === null) {
            return false;
        }

        if ($method === null || $method === '') {
            $method = 'index';
        }

        $method = $this->effectiveMethod($handle, $method, $component);

        $class = is_object($binding) ? $binding::class : $binding;

        try {
            $reflection = new ReflectionClass($class);
            $concrete = $this->concreteMethodAcceptance($reflection, Str::camel($method));
            if ($concrete !== null) {
                return $concrete;
            }

            return $this->supportsDynamicDispatch($reflection, $binding, $method);
        } catch (ReflectionException) {
            return null;
        }
    }

    private function effectiveMethod(string $handle, string $method, bool $component): string
    {
        $componentPartial = $component
            && $handle === 'partial'
            && ! in_array($method, ['exists', 'if_exists'], true);

        return $componentPartial ? 'index' : $method;
    }

    /** @param class-string $class */
    public function isA(string $handle, string $class): bool
    {
        $registered = $this->classFor($handle);

        return $registered !== null && is_a($registered, $class, true);
    }

    public function isCore(string $handle): bool
    {
        $class = $this->classFor($handle);
        if ($class === null || ! is_a($class, Tags::class, true)) {
            return false;
        }

        $file = (new ReflectionClass($class))->getFileName();
        $coreFile = (new ReflectionClass(Statamic::class))->getFileName();
        if (! is_string($file) || ! is_string($coreFile)) {
            return false;
        }

        $path = realpath($file) ?: $file;
        $corePath = realpath(dirname($coreFile)) ?: dirname($coreFile);
        if (! str_starts_with($path, $corePath.DIRECTORY_SEPARATOR)) {
            return false;
        }

        if ($class::handle() === $handle) {
            return true;
        }

        $aliases = $class::aliases();

        return is_array($aliases) && in_array($handle, $aliases, true);
    }

    public function isCoreDebugModifier(string $name): bool
    {
        $modifiers = app('statamic.modifiers');
        if (! $modifiers instanceof Collection) {
            return false;
        }

        return $modifiers->get($name) === 'Statamic\\Modifiers\\CoreModifiers@'.$name;
    }

    /** @return array<string, mixed> */
    public function debugModifierCacheContext(): array
    {
        $modifiers = app('statamic.modifiers');
        if (! $modifiers instanceof Collection) {
            return ['registry' => get_debug_type($modifiers)];
        }

        return [
            'dump' => $modifiers->get('dump'),
            'dd' => $modifiers->get('dd'),
            'ddd' => $modifiers->get('ddd'),
        ];
    }

    /** @return array<string, array{class: string, signature: string}> */
    public function cacheContext(): array
    {
        $bindings = $this->refreshBindings();
        $snapshot = $this->contextSnapshot($bindings);
        if ($snapshot === $this->cachedContextSnapshot && $this->cachedContext !== null) {
            return $this->cachedContext;
        }

        $context = [];
        $signatures = [];
        foreach ($bindings as $handle => $binding) {
            $class = is_object($binding) ? $binding::class : $binding;
            if (! class_exists($class)) {
                continue;
            }

            $signature = $signatures[$class] ??= $this->reflectionSignature($class);
            if (is_object($binding)) {
                $signature = hash('sha256', serialize([
                    $signature,
                    $this->objectDispatchState($binding),
                ]));
            }

            $context[$handle] = [
                'class' => $class,
                'signature' => $signature,
            ];
        }

        ksort($context);

        $this->cachedContextSnapshot = $snapshot;

        return $this->cachedContext = $context;
    }

    /**
     * @param  array<string, class-string|object>  $bindings
     * @return array<string, mixed>
     */
    private function contextSnapshot(array $bindings): array
    {
        $classes = [];
        $classNames = [];
        foreach ($bindings as $handle => $binding) {
            $class = is_object($binding) ? $binding::class : $binding;
            $classNames[$handle] = $class;
            $classes[$handle] = is_object($binding)
                ? ['class' => $class, 'dispatch' => $this->objectDispatchState($binding)]
                : ['class' => $class];
        }
        ksort($classes);

        $files = [];
        foreach (array_unique($classNames) as $class) {
            foreach ($this->reflectedFiles($class) as $file) {
                if (isset($files[$file])) {
                    continue;
                }

                clearstatcache(true, $file);
                $files[$file] = str_starts_with($class, 'Statamic\\')
                    ? [filemtime($file) ?: 0, filesize($file) ?: 0]
                    : (hash_file('sha256', $file) ?: '');
            }
        }
        ksort($files);

        return ['classes' => $classes, 'files' => $files];
    }

    /** @param class-string $class
     * @return list<string>
     */
    private function reflectedFiles(string $class): array
    {
        $files = [];
        foreach ($this->classHierarchy($class) as $reflection) {
            $file = $reflection->getFileName();
            if (is_string($file) && is_file($file)) {
                $files[$file] = true;
            }

            foreach ($this->allTraits($reflection->getName()) as $trait) {
                $traitFile = (new ReflectionClass($trait))->getFileName();
                if (is_string($traitFile) && is_file($traitFile)) {
                    $files[$traitFile] = true;
                }
            }
        }

        return array_keys($files);
    }

    /** @param class-string $class */
    private function reflectionSignature(string $class): string
    {
        $files = [];
        $methods = [];
        $traits = [];

        foreach ($this->classHierarchy($class) as $reflectedClass) {
            $file = $reflectedClass->getFileName();
            if (is_string($file) && is_file($file)) {
                $files[$file] = hash_file('sha256', $file) ?: '';
            }

            foreach ($reflectedClass->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflectedClass->getName()) {
                    continue;
                }

                $methods[] = implode(':', [
                    $reflectedClass->getName(),
                    $method->getName(),
                    $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private'),
                    (string) $method->getNumberOfRequiredParameters(),
                    (string) $method->getNumberOfParameters(),
                ]);
            }

            foreach ($this->allTraits($reflectedClass->getName()) as $trait) {
                $traits[$trait] = true;
                $traitReflection = new ReflectionClass($trait);
                $traitFile = $traitReflection->getFileName();
                if (is_string($traitFile) && is_file($traitFile)) {
                    $files[$traitFile] = hash_file('sha256', $traitFile) ?: '';
                }
            }
        }

        ksort($files);
        sort($methods);
        ksort($traits);

        return hash('sha256', serialize([
            'files' => $files,
            'methods' => $methods,
            'traits' => array_keys($traits),
        ]));
    }

    /**
     * @param class-string $class
     * @return list<ReflectionClass<object>>
     * @throws ReflectionException
     */
    private function classHierarchy(string $class): array
    {
        $classes = [];
        $reflection = new ReflectionClass($class);

        do {
            $classes[] = $reflection;
            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);

        return $classes;
    }

    /**
     * @param  class-string  $class
     * @return list<class-string>
     */
    private function allTraits(string $class): array
    {
        $traits = [];
        foreach (class_uses($class) ?: [] as $trait) {
            $traits[] = $trait;
            array_push($traits, ...$this->allTraits($trait));
        }

        return array_values(array_unique($traits));
    }

    /** @return array<string, class-string|object> */
    private function bindings(): array
    {
        if ($this->bindings !== null) {
            return $this->bindings;
        }

        $registered = app('statamic.tags');
        if (! is_iterable($registered)) {
            return $this->bindings = [];
        }

        $bindings = [];
        foreach ($registered as $handle => $binding) {
            if (! is_string($handle)) {
                continue;
            }

            if (is_string($binding) && class_exists($binding)) {
                $bindings[$handle] = $binding;
            } elseif (is_object($binding)) {
                $bindings[$handle] = $binding;
            }
        }

        return $this->bindings = $bindings;
    }

    /** @return array<string, class-string|object> */
    private function refreshBindings(): array
    {
        $this->bindings = null;

        return $this->bindings();
    }

    /** @return class-string|null */
    private function classFor(string $handle): ?string
    {
        $binding = $this->bindingFor($handle);
        $class = is_object($binding) ? $binding::class : $binding;

        return is_string($class) && class_exists($class) ? $class : null;
    }

    /** @return class-string|object|null */
    private function bindingFor(string $handle): string|object|null
    {
        $registered = app('statamic.tags');
        if ($registered instanceof Collection) {
            $binding = $registered->get($handle);
        } elseif (is_array($registered)) {
            $binding = $registered[$handle] ?? null;
        } elseif (is_iterable($registered)) {
            $binding = null;
            foreach ($registered as $registeredHandle => $candidate) {
                if (is_string($registeredHandle) && $registeredHandle === $handle) {
                    $binding = $candidate;
                    break;
                }
            }
        } else {
            return null;
        }

        if (is_object($binding)) {
            return $binding;
        }

        return is_string($binding) && class_exists($binding) ? $binding : null;
    }

    /** @param ReflectionClass<object> $reflection */
    private function concreteMethodAcceptance(ReflectionClass $reflection, string $method): ?bool
    {
        if (! $reflection->hasMethod($method)) {
            return null;
        }

        $concrete = $reflection->getMethod($method);

        return $concrete->isPublic()
            ? $concrete->getNumberOfRequiredParameters() === 0
            : null;
    }

    /** @param ReflectionClass<object> $reflection */
    private function supportsDynamicDispatch(
        ReflectionClass $reflection,
        string|object $binding,
        string $requestedMethod,
    ): bool {
        if ($reflection->hasMethod('__call')
            && $reflection->getMethod('__call')->getDeclaringClass()->getName() !== Tags::class) {
            return true;
        }

        $wildcard = $this->wildcardMethod($reflection, $binding);

        if (! $reflection->hasMethod($wildcard)) {
            return false;
        }

        $method = $reflection->getMethod($wildcard);

        if (! $method->isPublic() || $method->getNumberOfRequiredParameters() > 1) {
            return false;
        }

        $parameters = $method->getParameters();

        return ! isset($parameters[0])
            || $this->typeAcceptsMethodName($parameters[0]->getType(), $requestedMethod);
    }

    /** @param ReflectionClass<object> $reflection */
    private function wildcardMethod(ReflectionClass $reflection, string|object $binding): string
    {
        $wildcard = $this->objectWildcardMethod($reflection, $binding);
        if ($wildcard !== null) {
            return $wildcard;
        }

        $defaults = $reflection->getDefaultProperties();

        return is_string($defaults['wildcardMethod'] ?? null) ? $defaults['wildcardMethod'] : 'wildcard';
    }

    /** @param ReflectionClass<object> $reflection */
    private function objectWildcardMethod(ReflectionClass $reflection, string|object $binding): ?string
    {
        if (! is_object($binding) || ! $reflection->hasProperty('wildcardMethod')) {
            return null;
        }

        try {
            $wildcard = $reflection->getProperty('wildcardMethod')->getValue($binding);
        } catch (Throwable) {
            return null;
        }

        return is_string($wildcard) ? $wildcard : null;
    }

    private function typeAcceptsMethodName(?ReflectionType $type, string $method): bool
    {
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($this->typeAcceptsMethodName($member, $method)) {
                    return true;
                }
            }

            return false;
        }

        if (! $type instanceof ReflectionNamedType) {
            return false;
        }

        return match ($type->getName()) {
            'mixed', 'string', 'bool' => true,
            'int', 'float' => is_numeric($method),
            'callable' => is_callable($method),
            default => false,
        };
    }

    /** @return array{wildcard: string} */
    private function objectDispatchState(object $binding): array
    {
        $reflection = new ReflectionClass($binding);

        return ['wildcard' => $this->wildcardMethod($reflection, $binding)];
    }
}
