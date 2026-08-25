<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Analysis\Component;

#[RequiresPackage('statamic/cms', '^6.0')]
final class SpecialChildPlacementRule extends BaseRule
{
    public function getId(): string
    {
        return 'statamic-special-child-placement';
    }

    public function getDescription(): string
    {
        return 'Flags misplaced Statamic `slot` and `no_results` tags.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach ($document->queryComponents() as $node) {
            $component = Component::from($node);
            if ($component === null || ! in_array($component->handle, ['slot', 'no_results'], true)) {
                continue;
            }

            $parent = $node->getParent();
            $parentComponent = $parent instanceof ElementNode
                ? Component::from($parent)
                : null;

            if ($component->handle === 'slot') {
                if ($parentComponent === null || $parentComponent->handle !== 'partial') {
                    $this->reportOpeningTag($context, $node, '`slot` must be a direct child of a Statamic `partial` tag.');
                }

                continue;
            }

            if ($parentComponent === null
                || in_array($parentComponent->handle, ['partial', 'nocache', 'slot', 'no_results'], true)
            ) {
                $this->reportOpeningTag($context, $node, '`no_results` must be directly inside a Statamic tag that returns results.');
            }
        }
    }
}
