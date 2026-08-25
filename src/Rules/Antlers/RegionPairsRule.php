<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules\Antlers;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Parsing\Antlers\Regions\Scanner;
use Forte\Sheath\Statamic\Rules\BaseRule;

#[RequiresPackage('statamic/cms', '^6.0')]
final class RegionPairsRule extends BaseRule
{
    public function getId(): string
    {
        return 'statamic-antlers-region-pairs';
    }

    public function getDescription(): string
    {
        return 'Flags unbalanced or nested `@antlers` regions.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        $scan = app(Scanner::class)->scan($context->getOriginalSource());

        foreach ($scan->issues as $issue) {
            $message = match ($issue->kind) {
                'nested' => 'Nested `@antlers` regions are not supported. Remove the inner region markers.',
                'orphan-close' => '`@endantlers` has no matching `@antlers`. Remove it or add an opening marker.',
                default => '`@antlers` has no matching `@endantlers`. Add the closing marker.',
            };

            $context->reportAt(
                $this->originalPosition($context->getOriginalSource(), $issue->startOffset),
                $this->originalPosition($context->getOriginalSource(), $issue->endOffset),
                $message,
            );
        }
    }
}
