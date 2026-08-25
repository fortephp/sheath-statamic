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
final class PartialExistsRule extends ProjectAwareRule
{
    use DetectsOpaqueAttributes;

    public function getId(): string
    {
        return 'statamic-partial-exists';
    }

    public function getDescription(): string
    {
        return 'Flags missing literal partials in project views.';
    }

    protected function projectCacheContext(): array
    {
        return $this->projectCatalog()->viewCacheContext();
    }

    protected function projectCacheGroup(): string
    {
        return 'views';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach ($document->queryComponents() as $node) {
            $component = Component::from($node);
            if ($component === null
                || ! $this->checksPartial($component->handle, $component->method)
                || $this->elementHasUnmodelledAttributes($node)) {
                continue;
            }

            $paths = $this->explicitAttributeRenderPaths($node);
            if ($paths === null) {
                continue;
            }

            $reported = [];
            foreach ($paths as $path) {
                $target = $this->missingTarget(
                    new AttributeSource($path),
                    $component->method,
                    componentSyntax: true,
                );
                if ($target === null || isset($reported[$target])) {
                    continue;
                }
                $reported[$target] = true;

                $this->reportOpeningTag(
                    $context,
                    $node,
                    $this->missingPartialMessage($target),
                );
            }
        }

        foreach ($this->invocations($document) as $invocation) {
            $this->checkInvocation($context, $document, $invocation);
        }
    }

    private function checkInvocation(RuleContext $context, Document $document, Invocation $invocation): void
    {
        if (! $this->checksPartial($invocation->handle, $invocation->method)) {
            return;
        }

        $target = $this->missingTarget($invocation, $invocation->method);
        if ($target !== null) {
            $this->reportRange(
                $context,
                $document,
                $invocation->start,
                $invocation->end,
                $this->missingPartialMessage($target),
            );
        }
    }

    private function missingTarget(
        StaticSource $parameters,
        ?string $method,
        bool $componentSyntax = false,
    ): ?string {
        if ($this->shouldRender($parameters) !== true) {
            return null;
        }

        if ($componentSyntax && $method !== null) {
            $target = $method;
        } else {
            $src = $parameters->firstStaticParameter(['src']);
            if ($src->present && ! $src->known) {
                return null;
            }

            $target = $src->present ? $src->value : $method;
        }

        return $target !== null && $this->projectCatalog()->partialExists($target) === false
            ? $target
            : null;
    }

    private function shouldRender(StaticSource $parameters): ?bool
    {
        $when = $parameters->firstStaticParameter(['when']);
        if ($when->present) {
            return $parameters->firstStaticTruthiness(['when']);
        }

        $unless = $parameters->firstStaticParameter(['unless']);
        if ($unless->present) {
            $truthy = $parameters->firstStaticTruthiness(['unless']);

            return $truthy === null ? null : ! $truthy;
        }

        return true;
    }

    private function checksPartial(string $handle, ?string $method): bool
    {
        return $handle === 'partial'
            && $this->isCoreTag('partial')
            && ! in_array($method, ['exists', 'if_exists'], true);
    }

    private function missingPartialMessage(string $target): string
    {
        return "Statamic partial `{$target}` does not exist in the project views.";
    }
}
