<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules\Antlers;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Parsing\Antlers\Regions\Parser;
use Forte\Sheath\Statamic\Parsing\Antlers\Regions\Region;
use Forte\Sheath\Statamic\Parsing\Antlers\Regions\Scanner;
use Forte\Sheath\Statamic\Rules\BaseRule;
use Statamic\View\Antlers\Language\Exceptions\SyntaxErrorException;

#[RequiresPackage('statamic/cms', '^6.0')]
final class RegionSyntaxRule extends BaseRule
{
    public function getId(): string
    {
        return 'statamic-antlers-region-syntax';
    }

    public function getDescription(): string
    {
        return 'Flags invalid Antlers syntax inside Blade.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        $source = $context->getOriginalSource();
        $scan = app(Scanner::class)->scan($source);
        $issueIndex = 0;

        foreach ($scan->regions as $region) {
            while (isset($scan->issues[$issueIndex])
                && $scan->issues[$issueIndex]->startOffset < $region->startOffset) {
                $issueIndex++;
            }

            $hasStructuralIssue = isset($scan->issues[$issueIndex])
                && $scan->issues[$issueIndex]->startOffset < $region->endOffset;
            if ($region->nested || $hasStructuralIssue) {
                continue;
            }

            $body = substr($source, $region->contentStartOffset, $region->contentEndOffset - $region->contentStartOffset);

            $failure = app(Parser::class)->parse($document, $region, $body)->failure;
            if ($failure instanceof SyntaxErrorException) {
                $this->reportSyntaxError($failure, $region, $body, $source, $context);
            }
        }
    }

    private function reportSyntaxError(
        SyntaxErrorException $exception,
        Region $region,
        string $body,
        string $source,
        RuleContext $context,
    ): void {
        $relativeStart = $this->nodeOffset($exception, 'startPosition');
        $relativeEnd = $this->nodeOffset($exception, 'endPosition');
        $start = $region->contentStartOffset + $this->characterOffsetToByteOffset($body, $relativeStart);
        $end = $region->contentStartOffset + $this->characterOffsetToByteOffset($body, max($relativeStart + 1, $relativeEnd + 1));

        $context->reportAt(
            $this->originalPosition($source, min($start, $region->contentEndOffset)),
            $this->originalPosition($source, min($end, $region->contentEndOffset)),
            'Invalid Antlers syntax: '.$exception->getMessage(),
        );
    }

    private function nodeOffset(SyntaxErrorException $exception, string $property): int
    {
        $position = $exception->node->{$property} ?? null;
        $offset = is_object($position) ? ($position->offset ?? null) : null;

        return is_int($offset) && $offset >= 0 ? $offset : 0;
    }
}
