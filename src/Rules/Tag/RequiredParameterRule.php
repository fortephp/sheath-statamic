<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules\Tag;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Analysis\Component;
use Forte\Sheath\Statamic\Analysis\Parameter\AttributeSource;
use Forte\Sheath\Statamic\Rules\RegistryAwareRule;

#[RequiresPackage('statamic/cms', '^6.0')]
final class RequiredParameterRule extends RegistryAwareRule
{
    use DetectsOpaqueAttributes;

    public function getId(): string
    {
        return 'statamic-required-tag-parameter';
    }

    public function getDescription(): string
    {
        return 'Flags required parameters missing from Statamic Blade tags.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach ($document->queryComponents() as $node) {
            $component = Component::from($node);
            if ($component === null || $component->isCompilerEmpty()) {
                continue;
            }

            $required = $this->requirementsFor($component->handle, $component->method);
            if ($required === null || $this->elementHasUnmodelledAttributes($node)) {
                continue;
            }

            $paths = $this->explicitAttributeRenderPaths($node, $required['aliases'], retainSpecificBindings: true);
            if ($paths === null) {
                continue;
            }

            $missingStates = [];
            foreach ($paths as $path) {
                $missingStates[] = $this->requiredParameterState(new AttributeSource($path), $required) === 'missing';
            }

            $needsCorrelation = $this->attributeRenderPathsNeedIndependentConditionCorrelation(
                $node,
                $required['aliases'],
            );
            $shouldReport = $needsCorrelation
                ? $missingStates !== [] && ! in_array(false, $missingStates, true)
                : in_array(true, $missingStates, true);

            if ($shouldReport) {
                $this->reportOpeningTag(
                    $context,
                    $node,
                    "Statamic tag `{$component->handle}".($component->method === null ? '' : ':'.$component->method)."` needs {$this->parameterRequirement($required['name'])}.",
                );
            }
        }
    }
}
