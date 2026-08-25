<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules\Fluent;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Parsing\Fluent\CallScanner;
use Forte\Sheath\Statamic\Rules\RegistryAwareRule;

#[RequiresPackage('statamic/cms', '^6.0')]
final class RequiredParametersRule extends RegistryAwareRule
{
    public function getId(): string
    {
        return 'statamic-fluent-required-parameters';
    }

    public function getDescription(): string
    {
        return 'Flags required parameters missing from executed fluent calls.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach (app(CallScanner::class)->scan($document) as $call) {
            $required = $this->requirementsFor($call->handle, $call->method);
            if ($required === null) {
                continue;
            }

            $state = $this->requiredParameterState($call, $required);
            if ($state !== 'missing') {
                continue;
            }

            $this->reportRange(
                $context,
                $document,
                $call->start,
                $call->end,
                "Fluent call `{$call->displayName()}` needs {$this->parameterRequirement($required['name'])}.",
            );
        }
    }
}
