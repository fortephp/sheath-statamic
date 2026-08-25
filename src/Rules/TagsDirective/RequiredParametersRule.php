<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules\TagsDirective;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Parsing\TagsDirective\Scanner;
use Forte\Sheath\Statamic\Rules\RegistryAwareRule;

#[RequiresPackage('statamic/cms', '^6.0')]
final class RequiredParametersRule extends RegistryAwareRule
{
    public function getId(): string
    {
        return 'statamic-tags-directive-required-parameters';
    }

    public function getDescription(): string
    {
        return 'Flags required parameters missing from `@tags` definitions.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach (app(Scanner::class)->scan($document) as $tag) {
            $required = $this->requirementsFor($tag->handle, $tag->method);
            if ($required === null
                || $this->requiredParameterState($tag, $required) !== 'missing') {
                continue;
            }

            $this->reportRange(
                $context,
                $document,
                $tag->start,
                $tag->end,
                "`@tags` definition `{$tag->displayName()}` needs {$this->parameterRequirement($required['name'])}.",
            );
        }
    }
}
