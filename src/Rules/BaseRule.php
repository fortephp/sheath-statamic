<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

abstract class BaseRule extends AbstractRule
{
    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    protected function reportOpeningTag(RuleContext $context, ElementNode $node, string $message): void
    {
        $document = $node->getDocument();
        $this->reportRange(
            $context,
            $document,
            $node->startOffset(),
            $document->findOpeningTagEndPosition($node->index()),
            $message,
        );
    }

    protected function reportRange(
        RuleContext $context,
        Document $document,
        int $start,
        int $end,
        string $message,
    ): void {
        $context->reportAt(
            Position::fromOffset($document, $start),
            Position::fromOffset($document, $end),
            $message,
        );
    }

    protected function parameterRequirement(string $name): string
    {
        $names = explode(', ', str_replace(', or ', ', ', $name));
        if (count($names) === 1) {
            return "a `{$name}` parameter";
        }

        $last = array_pop($names);

        return 'a `'.implode('`, `', $names)."`, or `{$last}` parameter";
    }

    protected function originalPosition(string $source, int $offset): Position
    {
        $offset = max(0, min($offset, strlen($source)));
        $prefix = substr($source, 0, $offset);
        $line = substr_count($prefix, "\n") + 1;
        $lastNewline = strrpos($prefix, "\n");
        $lineStart = $lastNewline === false ? 0 : $lastNewline + 1;
        $linePrefix = substr($source, $lineStart, $offset - $lineStart);
        $character = preg_match('//u', $linePrefix) === 1
            ? mb_strlen($linePrefix, 'UTF-8') + 1
            : strlen($linePrefix) + 1;

        return new Position($offset, $line, $character);
    }

    protected function characterOffsetToByteOffset(string $source, int $offset): int
    {
        return strlen(mb_substr($source, 0, $offset));
    }
}
