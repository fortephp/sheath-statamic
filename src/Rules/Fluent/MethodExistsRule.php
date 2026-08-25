<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules\Fluent;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Analysis\Tag\MethodRequirement;
use Forte\Sheath\Statamic\Parsing\Fluent\CallScanner;
use Forte\Sheath\Statamic\Rules\RegistryAwareRule;

#[RequiresPackage('statamic/cms', '^6.0')]
final class MethodExistsRule extends RegistryAwareRule
{
    public function getId(): string
    {
        return 'statamic-fluent-tag-method-exists';
    }

    public function getDescription(): string
    {
        return 'Flags unsupported methods in executed Statamic fluent calls.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach (app(CallScanner::class)->scan($document) as $call) {
            if (! $this->catalog()->has($call->handle)) {
                continue;
            }

            if ($this->isCoreTag($call->handle)
                && MethodRequirement::isMissing($call->handle, $call->method)) {
                $message = "Fluent call for `{$call->handle}` needs a method name.";
            } elseif ($this->catalog()->acceptsMethod($call->handle, $call->method) === false) {
                $method = $call->method ?? 'index';
                $message = "Statamic tag `{$call->handle}` has no `{$method}` method.";
            } else {
                continue;
            }

            $this->reportRange(
                $context,
                $document,
                $call->nameStart,
                $call->nameEnd,
                $message,
            );
        }
    }
}
