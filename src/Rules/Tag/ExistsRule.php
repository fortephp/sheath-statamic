<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules\Tag;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Analysis\Component;
use Forte\Sheath\Statamic\Rules\RegistryAwareRule;

#[RequiresPackage('statamic/cms', '^6.0')]
final class ExistsRule extends RegistryAwareRule
{
    public function getId(): string
    {
        return 'statamic-tag-exists';
    }

    public function getDescription(): string
    {
        return 'Flags unregistered Statamic Blade tags.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach ($document->queryComponents() as $node) {
            $component = Component::from($node);
            if ($component === null || ! $this->isUnknown($component)) {
                continue;
            }

            $this->reportOpeningTag($context, $node, "Unknown Statamic tag `{$component->handle}`.");
        }
    }

    private function isUnknown(Component $component): bool
    {
        return ! $component->isCompilerEmpty()
            && ! in_array($component->handle, ['slot', 'no_results'], true)
            && ! $this->catalog()->has($component->handle);
    }
}
