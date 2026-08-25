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
final class CollectionMethodCompatibilityRule extends ProjectAwareRule
{
    use DetectsOpaqueAttributes;

    /** @var list<string> */
    private const METHODS = ['next', 'previous', 'older', 'newer'];

    public function getId(): string
    {
        return 'statamic-collection-method-compatibility';
    }

    public function getDescription(): string
    {
        return 'Flags unsupported collection navigation options and settings.';
    }

    protected function projectCacheContext(): array
    {
        return $this->projectCatalog()->collectionCacheContext();
    }

    protected function projectCacheGroup(): string
    {
        return 'collections';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach ($document->queryComponents() as $node) {
            $component = Component::from($node);
            if ($component === null
                || ! $this->isNavigationInvocation($component->handle, $component->method)
                || $this->elementHasUnmodelledAttributes($node)) {
                continue;
            }

            $paths = $this->explicitAttributeRenderPaths($node);
            if ($paths === null) {
                continue;
            }

            $reported = [];
            foreach ($paths as $path) {
                $parameters = new AttributeSource($path);
                foreach ($this->problems($component->method, $parameters) as $problem) {
                    if (isset($reported[$problem])) {
                        continue;
                    }
                    $reported[$problem] = true;
                    $this->reportOpeningTag($context, $node, $problem);
                }
            }
        }

        foreach ($this->invocations($document) as $invocation) {
            $this->reportInvocationProblems($context, $document, $invocation);
        }
    }

    private function reportInvocationProblems(
        RuleContext $context,
        Document $document,
        Invocation $invocation,
    ): void {
        if (! $this->isNavigationInvocation($invocation->handle, $invocation->method)) {
            return;
        }

        foreach ($this->problems($invocation->method, $invocation) as $problem) {
            $this->reportRange($context, $document, $invocation->start, $invocation->end, $problem);
        }
    }

    /** @return list<string> */
    private function problems(string $method, StaticSource $parameters): array
    {
        $problems = [];
        foreach (['paginate', 'offset'] as $parameter) {
            if ($parameters->firstStaticParameter([$parameter])->present) {
                $problems[] = "`collection:{$method}` does not support `{$parameter}`.";
            }
        }

        $current = $this->knownNonEmptyValue($parameters->firstStaticParameter(['current']));
        if ($current === null) {
            return $problems;
        }

        $sort = $parameters->firstStaticParameter(['order_by', 'sort']);
        $sortValue = $this->knownNonEmptyValue($sort);
        if ($sort->present && $sortValue === null) {
            return $problems;
        }

        $projectProblem = $this->projectCatalog()->collectionMethodProblem(
            $current,
            $method,
            $sortValue,
        );
        if (is_string($projectProblem)) {
            $problems[] = $projectProblem;
        }

        return array_values(array_unique($problems));
    }

    private function knownNonEmptyValue(StaticValue $value): ?string
    {
        return $value->known && $value->value !== null && $value->value !== ''
            ? $value->value
            : null;
    }

    /** @phpstan-assert-if-true string $method */
    private function isNavigationInvocation(string $handle, ?string $method): bool
    {
        return $handle === 'collection'
            && $this->isCoreTag('collection')
            && in_array($method, self::METHODS, true);
    }
}
