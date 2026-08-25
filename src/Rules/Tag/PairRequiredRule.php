<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules\Tag;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Analysis\Component;
use Forte\Sheath\Statamic\Parsing\Fluent\CallScanner;
use Forte\Sheath\Statamic\Parsing\TagsDirective\Scanner as TagsDirectiveScanner;
use Forte\Sheath\Statamic\Rules\RegistryAwareRule;

#[RequiresPackage('statamic/cms', '^6.0')]
final class PairRequiredRule extends RegistryAwareRule
{
    public function getId(): string
    {
        return 'statamic-tag-pair-required';
    }

    public function getDescription(): string
    {
        return 'Requires paired content for the Statamic `scope` tag.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (! $this->isCoreTag('scope')) {
            return;
        }

        foreach ($document->queryComponents() as $node) {
            $component = Component::from($node);
            if ($component?->handle !== 'scope' || $node->isPaired()) {
                continue;
            }

            $this->reportOpeningTag($context, $node, 'Statamic `scope` needs paired content.');
        }

        foreach (app(CallScanner::class)->scan($document) as $call) {
            if ($call->handle !== 'scope' || $call->pairedContentState !== 'empty') {
                continue;
            }

            $this->reportRange(
                $context,
                $document,
                $call->start,
                $call->end,
                'Statamic `scope` needs non-empty paired content.',
            );
        }

        foreach (app(TagsDirectiveScanner::class)->scan($document) as $tag) {
            if ($tag->handle !== 'scope') {
                continue;
            }

            $this->reportRange(
                $context,
                $document,
                $tag->start,
                $tag->end,
                '`@tags` cannot supply the paired content required by `scope`.',
            );
        }
    }
}
