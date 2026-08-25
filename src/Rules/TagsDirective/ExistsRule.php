<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules\TagsDirective;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Parsing\TagsDirective\Scanner;
use Forte\Sheath\Statamic\Rules\RegistryAwareRule;

#[RequiresPackage('statamic/cms', '^6.0')]
final class ExistsRule extends RegistryAwareRule
{
    public function getId(): string
    {
        return 'statamic-tags-directive-tag-exists';
    }

    public function getDescription(): string
    {
        return 'Flags unknown tags and unsupported methods in `@tags` definitions.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach (app(Scanner::class)->scan($document) as $tag) {
            if (! $this->catalog()->has($tag->handle)) {
                $message = "`@tags` definition uses unknown Statamic tag `{$tag->handle}`.";
            } elseif ($this->catalog()->acceptsMethod($tag->handle, $tag->method) === false) {
                $method = $tag->method ?? 'index';
                $message = "`@tags` definition `{$tag->handle}` has no `{$method}` method.";
            } else {
                continue;
            }

            $this->reportRange(
                $context,
                $document,
                $tag->start,
                $tag->end,
                $message,
            );
        }
    }
}
