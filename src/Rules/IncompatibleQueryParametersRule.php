<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Analysis\Component;
use Forte\Sheath\Statamic\Analysis\Parameter\AttributeSource;
use Forte\Sheath\Statamic\Analysis\Parameter\StaticSource;
use Forte\Sheath\Statamic\Analysis\Parameter\StaticValue;
use Forte\Sheath\Statamic\Analysis\Tag\Invocation;

#[RequiresPackage('statamic/cms', '^6.0')]
final class IncompatibleQueryParametersRule extends RegistryAwareRule
{
    use DetectsOpaqueAttributes;

    public function getId(): string
    {
        return 'statamic-incompatible-query-parameters';
    }

    public function getDescription(): string
    {
        return 'Flags incompatible Statamic query parameters.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach ($document->queryComponents() as $node) {
            $component = Component::from($node);
            if ($component === null
                || ! $this->usesGuardedResultPath($component->handle, $component->method)
                || $this->elementHasUnmodelledAttributes($node)) {
                continue;
            }

            $names = ['paginate', 'limit', 'offset', 'chunk'];
            $paths = $this->explicitAttributeRenderPaths($node, $names);
            if ($paths === null) {
                continue;
            }

            $pathProblems = [];
            foreach ($paths as $path) {
                $pathProblems[] = $this->problems(new AttributeSource($path));
            }
            if ($pathProblems === []) {
                continue;
            }

            $reported = $this->attributeRenderPathsNeedIndependentConditionCorrelation($node, $names)
                ? array_values(array_intersect(...$pathProblems))
                : array_values(array_unique(array_merge(...$pathProblems)));
            foreach ($reported as $problem) {
                $this->reportOpeningTag($context, $node, $problem);
            }
        }

        foreach ($this->invocations($document) as $invocation) {
            $this->reportInvocationProblems($context, $document, $invocation);
        }
    }

    private function reportInvocationProblems(RuleContext $context, Document $document, Invocation $invocation): void
    {
        if (! $this->usesGuardedResultPath($invocation->handle, $invocation->method)) {
            return;
        }

        foreach ($this->problems($invocation) as $problem) {
            $this->reportRange($context, $document, $invocation->start, $invocation->end, $problem);
        }
    }

    /** @return list<string> */
    private function problems(StaticSource $parameters): array
    {
        if ($parameters->hasParameter(['paginate']) && $parameters->hasParameter(['chunk'])) {
            return ['`paginate` and `chunk` cannot be used together. Remove one.'];
        }

        if ($this->hasNumericPaginationConflict($parameters)) {
            return ['Numeric `paginate` cannot be used with `limit`. Remove `limit` or use boolean `paginate`.'];
        }

        return [];
    }

    private function usesGuardedResultPath(string $handle, ?string $method): bool
    {
        if (! $this->isCoreTag($handle)) {
            return false;
        }

        return match ($handle) {
            'collection', 'taxonomy' => $method !== 'count',
            'dictionary', 'query' => true,
            'form' => $method === 'submissions',
            'search' => $method === 'results',
            'users' => in_array($method, [null, 'index'], true),
            default => false,
        };
    }

    private function hasNumericPaginationConflict(StaticSource $parameters): bool
    {
        if (! $parameters->hasParameter(['paginate']) || ! $parameters->hasParameter(['limit'])) {
            return false;
        }

        $paginate = $parameters->firstStaticParameter(['paginate']);

        return ! $this->isStaticTrue($paginate)
            && $this->nonZeroStaticInteger($paginate)
            && $this->nonZeroStaticInteger($parameters->firstStaticParameter(['limit']));
    }

    private function nonZeroStaticInteger(StaticValue $value): bool
    {
        if (! $value->known || $value->value === null) {
            return false;
        }

        $literal = trim($value->value);
        if (strcasecmp($literal, 'true') === 0) {
            return true;
        }

        return (int) $literal !== 0;
    }

    private function isStaticTrue(StaticValue $value): bool
    {
        return $value->known
            && $value->value !== null
            && strcasecmp(trim($value->value), 'true') === 0;
    }
}
