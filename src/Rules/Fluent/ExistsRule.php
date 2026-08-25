<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules\Fluent;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Parsing\Fluent\CallScanner;
use Forte\Sheath\Statamic\Rules\RegistryAwareRule;

#[RequiresPackage('statamic/cms', '^6.0')]
final class ExistsRule extends RegistryAwareRule
{
    public function getId(): string
    {
        return 'statamic-fluent-tag-exists';
    }

    public function getDescription(): string
    {
        return 'Flags unknown Statamic tags in executed fluent calls.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach (app(CallScanner::class)->scan($document) as $call) {
            if (! $this->catalog()->has($call->handle)) {
                $this->reportRange(
                    $context,
                    $document,
                    $call->nameStart,
                    $call->nameEnd,
                    "Fluent call uses unknown Statamic tag `{$call->handle}`.",
                );
            }
        }
    }
}
