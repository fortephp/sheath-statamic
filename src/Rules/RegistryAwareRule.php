<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules;

use Forte\Ast\Document\Document;
use Forte\Sheath\Contracts\SharesCacheContext;
use Forte\Sheath\Statamic\Analysis\Parameter\Requirements;
use Forte\Sheath\Statamic\Analysis\Parameter\StaticSource;
use Forte\Sheath\Statamic\Analysis\Parameter\StaticValue;
use Forte\Sheath\Statamic\Analysis\Tag\Invocation;
use Forte\Sheath\Statamic\Catalog\TagCatalog;
use Forte\Sheath\Statamic\Parsing\Fluent\CallScanner;
use Forte\Sheath\Statamic\Parsing\TagsDirective\Scanner as TagsDirectiveScanner;

abstract class RegistryAwareRule extends BaseRule implements SharesCacheContext
{
    public function cacheContext(array $options): array|string
    {
        if (! function_exists('app') || ! app()->bound('statamic.tags')) {
            return 'statamic-registry-unavailable';
        }

        return ['schema' => 1, 'tags' => $this->catalog()->cacheContext()];
    }

    public function cacheContextGroup(array $options): string
    {
        return 'fortephp/sheath-statamic:tags';
    }

    protected function catalog(): TagCatalog
    {
        return app(TagCatalog::class);
    }

    /** @return iterable<Invocation> */
    protected function invocations(Document $document): iterable
    {
        yield from app(CallScanner::class)->scan($document);
        yield from app(TagsDirectiveScanner::class)->scan($document);
    }

    /** @return array{name: string, aliases: list<string>, allowZero: bool, strategy?: 'any'}|null */
    protected function requirementsFor(string $handle, ?string $method): ?array
    {
        $requirements = Requirements::for($handle, $method);
        if ($requirements === null
            || ! $this->isCoreTag($handle)
            || $this->catalog()->acceptsMethod($handle, $method) !== true) {
            return null;
        }

        return $requirements;
    }

    /**
     * @param  array{name: string, aliases: list<string>, allowZero: bool, strategy?: 'any'}  $requirement
     * @return 'missing'|'satisfied'|'unknown'
     */
    protected function requiredParameterState(StaticSource $parameters, array $requirement): string
    {
        if (($requirement['strategy'] ?? null) !== 'any') {
            return $this->staticParameterState(
                $parameters->firstStaticParameter($requirement['aliases']),
                $requirement['allowZero'],
            );
        }

        $unknown = false;
        foreach ($requirement['aliases'] as $alias) {
            $state = $this->staticParameterState(
                $parameters->firstStaticParameter([$alias]),
                $requirement['allowZero'],
            );
            if ($state === 'satisfied') {
                return 'satisfied';
            }

            $unknown = $unknown || $state === 'unknown';
        }

        return $unknown ? 'unknown' : 'missing';
    }

    /**
     * @return 'missing'|'satisfied'|'unknown'
     */
    private function staticParameterState(StaticValue $parameter, bool $allowZero): string
    {
        if (! $parameter->present) {
            return 'missing';
        }

        if (! $parameter->known || $parameter->value === null) {
            return 'unknown';
        }

        $value = trim($parameter->value);

        return $value !== ''
            && $parameter->value !== 'false'
            && ($allowZero || $value !== '0')
                ? 'satisfied'
                : 'missing';
    }

    protected function isCoreTag(string $handle): bool
    {
        return $this->catalog()->isCore($handle);
    }
}
