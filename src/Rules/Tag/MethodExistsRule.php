<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules\Tag;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Analysis\Component;
use Forte\Sheath\Statamic\Analysis\Tag\MethodRequirement;
use Forte\Sheath\Statamic\Rules\RegistryAwareRule;

#[RequiresPackage('statamic/cms', '^6.0')]
final class MethodExistsRule extends RegistryAwareRule
{
    public function getId(): string
    {
        return 'statamic-tag-method-exists';
    }

    public function getDescription(): string
    {
        return 'Flags unsupported Statamic Blade tag methods.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach ($document->queryComponents() as $node) {
            $component = Component::from($node);
            if ($component === null
                || $component->isCompilerEmpty()
                || ! $this->catalog()->has($component->handle)) {
                continue;
            }

            if ($this->isCoreTag($component->handle)
                && MethodRequirement::isMissing($component->handle, $component->method)) {
                $this->reportOpeningTag(
                    $context,
                    $node,
                    "Statamic tag `{$component->handle}` needs a method name.",
                );

                continue;
            }

            if ($this->catalog()->acceptsComponentMethod($component->handle, $component->method) === false) {
                $method = $component->method ?? 'index';
                $this->reportOpeningTag($context, $node, "Statamic tag `{$component->handle}` has no `{$method}` method.");
            }
        }
    }
}
