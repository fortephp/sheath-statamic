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
use Forte\Sheath\Statamic\Analysis\Tag\Invocation;

#[RequiresPackage('statamic/cms', '^6.0')]
final class AssetsPathRequiresContainerRule extends RegistryAwareRule
{
    use DetectsOpaqueAttributes;

    public function getId(): string
    {
        return 'statamic-assets-path-requires-container';
    }

    public function getDescription(): string
    {
        return 'Requires a container for `assets` path queries.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach ($document->queryComponents() as $node) {
            $component = Component::from($node);
            if ($component === null
                || ! $this->isAnalyzableAssetsQuery($component->handle, $component->method)
                || $this->elementHasUnmodelledAttributes($node)) {
                continue;
            }

            $names = ['path', 'container', 'handle', 'id', 'collection'];
            $paths = $this->explicitAttributeRenderPaths($node, $names);
            if ($paths === null) {
                continue;
            }

            $problems = [];
            foreach ($paths as $path) {
                $problems[] = $this->hasProblem(new AttributeSource($path));
            }

            $needsCorrelation = $this->attributeRenderPathsNeedIndependentConditionCorrelation($node, $names);
            $shouldReport = $needsCorrelation
                ? $problems !== [] && ! in_array(false, $problems, true)
                : in_array(true, $problems, true);
            if ($shouldReport) {
                $this->reportOpeningTag(
                    $context,
                    $node,
                    '`assets` queries with `path` also need `container`, `handle`, `id`, or `collection`.',
                );
            }
        }

        foreach ($this->invocations($document) as $invocation) {
            $this->checkInvocation($context, $document, $invocation);
        }
    }

    private function checkInvocation(RuleContext $context, Document $document, Invocation $invocation): void
    {
        if ($this->isAnalyzableAssetsQuery($invocation->handle, $invocation->method)
            && $this->hasProblem($invocation)) {
            $this->reportRange(
                $context,
                $document,
                $invocation->start,
                $invocation->end,
                '`assets` queries with `path` also need `container`, `handle`, `id`, or `collection`.',
            );
        }
    }

    private function isAnalyzableAssetsQuery(string $handle, ?string $method): bool
    {
        return $handle === 'assets'
            && $this->isCoreTag('assets')
            && in_array($method, [null, 'index'], true);
    }

    private function hasProblem(StaticSource $parameters): bool
    {
        return $parameters->hasParameter(['path'])
            && $parameters->firstStaticTruthiness(['path']) === true
            && ! $this->mayHaveContainer($parameters);
    }

    private function mayHaveContainer(StaticSource $parameters): bool
    {
        foreach (['container', 'handle', 'id', 'collection'] as $name) {
            if ($parameters->hasParameter([$name])
                && $parameters->firstStaticTruthiness([$name]) !== false) {
                return true;
            }
        }

        return false;
    }
}
