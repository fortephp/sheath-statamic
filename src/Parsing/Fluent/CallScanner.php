<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Fluent;

use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Ast\PhpTagNode;
use Forte\Sheath\Statamic\Analysis\Parameter\Writes;
use Forte\Sheath\Statamic\Parsing\Php\ParameterWriteParser;
use Forte\Sheath\Statamic\Parsing\Php\TokenStream;
use Illuminate\Support\Str;
use WeakMap;

final class CallScanner
{
    private const ITERATOR_FUNCTIONS = ['iterator_apply', 'iterator_count', 'iterator_to_array'];

    private const MEMBER_OPERATORS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    private const STRING_FUNCTIONS = ['strval', 'e'];

    /** @var WeakMap<Document, list<Call>> */
    private WeakMap $cache;

    public function __construct(private readonly ParameterWriteParser $writeParser)
    {
        $this->cache = new WeakMap;
    }

    /** @return list<Call> */
    public function scan(Document $document): array
    {
        if (isset($this->cache[$document])) {
            return $this->cache[$document];
        }

        /** @var array<string, Call> $calls */
        $calls = [];

        foreach ($document->allOfType(Node::class, true) as $node) {
            if ($node instanceof EchoNode) {
                $this->scanFragmentInto(
                    $calls,
                    $node->getDocumentContent(),
                    $node->content(),
                    $node->startOffset(),
                    'string',
                );
            } elseif ($node instanceof DirectiveNode) {
                $this->scanDirective($calls, $node);
            } elseif ($node instanceof PhpBlockNode) {
                $this->scanFragmentInto(
                    $calls,
                    $node->getDocumentContent(),
                    $node->content(),
                    $node->startOffset(),
                    null,
                );
            } elseif ($node instanceof PhpTagNode) {
                $this->scanFragmentInto(
                    $calls,
                    $node->getDocumentContent(),
                    $node->content(),
                    $node->startOffset(),
                    null,
                );
            }

            // Bound ordinary-element attributes are PHP expressions but are
            // not always represented by a standalone Blade construct node.
            if ($node instanceof ElementNode) {
                $this->scanBoundAttributes($calls, $node);
            }
        }

        ksort($calls);

        return $this->cache[$document] = array_values($calls);
    }

    /** @param array<string, Call> $calls */
    private function scanDirective(array &$calls, DirectiveNode $directive): void
    {
        $arguments = $directive->arguments();
        if ($arguments === null) {
            return;
        }

        $base = $this->scanFragmentInto(
            $calls,
            $directive->getDocumentContent(),
            $arguments,
            $directive->startOffset(),
            null,
        );
        if ($base === null || ! in_array(strtolower($directive->nameText()), ['foreach', 'forelse'], true)) {
            return;
        }

        $iterable = $this->foreachIterable($arguments);
        if ($iterable !== null) {
            $this->merge(
                $calls,
                $this->scanCode($iterable['source'], $base + $iterable['offset'], 'iteration'),
            );
        }
    }

    /** @param array<string, Call> $calls */
    private function scanBoundAttributes(array &$calls, ElementNode $element): void
    {
        foreach ($element->attributes() as $attribute) {
            /** @var Attribute $attribute */
            if (! $attribute->isBound() || $attribute->hasComplexValue()) {
                continue;
            }

            $value = $attribute->valueText();
            if ($value === null) {
                continue;
            }

            $raw = substr(
                $attribute->getDocument()->source(),
                $attribute->startOffset(),
                $attribute->endOffset() - $attribute->startOffset(),
            );
            $this->scanFragmentInto($calls, $raw, $value, $attribute->startOffset(), null);
        }
    }

    /**
     * @return list<Call>
     */
    private function scanCode(string $source, int $baseOffset, ?string $implicitTerminal): array
    {
        if (stripos($source, 'statamic') === false) {
            return [];
        }

        $stream = TokenStream::from($source);
        if ($stream === null) {
            return [];
        }

        $calls = [];
        for ($index = 0; $index < $stream->count(); $index++) {
            $call = $this->parseCall($stream, $index, $baseOffset, $implicitTerminal);
            if ($call !== null) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    private function parseCall(TokenStream $stream, int $startIndex, int $baseOffset, ?string $implicitTerminal): ?Call
    {
        $start = $stream->token($startIndex);
        if ($start === null) {
            return null;
        }

        $openIndex = $this->tagFactoryOpen($stream, $startIndex, $start);
        if ($openIndex === null || ($stream->token($openIndex)['text'] ?? null) !== '(') {
            return null;
        }

        $closeIndex = $stream->matchingDelimiter($openIndex);
        if ($closeIndex === null) {
            return null;
        }

        $arguments = $stream->splitTopLevel($openIndex + 1, $closeIndex);
        if (count($arguments) !== 1) {
            return null;
        }

        $literal = $stream->literalString($arguments[0]['start'], $arguments[0]['end']);
        if ($literal === null || $literal['value'] === '') {
            return null;
        }

        [$handle, $method] = $this->splitTagName($literal['value']);
        $terminal = null;
        $writes = new Writes;
        $pairedContentState = 'empty';
        $chainEnd = $closeIndex;
        $cursor = $stream->nextSignificant($closeIndex + 1);

        while ($cursor !== null && in_array($stream->token($cursor)['id'] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            $methodIndex = $stream->nextSignificant($cursor + 1);
            $methodToken = $methodIndex === null ? null : $stream->token($methodIndex);
            if ($methodIndex === null || $methodToken === null || $methodToken['id'] !== T_STRING) {
                return null;
            }

            $methodName = $methodToken['text'];
            $methodOpen = $stream->nextSignificant($methodIndex + 1);
            if ($methodOpen === null || ($stream->token($methodOpen)['text'] ?? null) !== '(') {
                if (! $this->propertyAccessDoesNotFetch($stream, $startIndex, $methodIndex, $methodOpen)) {
                    $terminal = 'fetch';
                }
                $chainEnd = $methodIndex;
                break;
            }

            $methodClose = $stream->matchingDelimiter($methodOpen);
            if ($methodClose === null) {
                return null;
            }

            $methodArguments = $stream->splitTopLevel($methodOpen + 1, $methodClose);
            $lower = strtolower($methodName);
            $terminal = $this->applyMethod(
                $stream,
                $methodName,
                $lower,
                $methodArguments,
                $writes,
                $pairedContentState,
            ) ?? $terminal;

            $chainEnd = $methodClose;
            if (in_array($lower, ['fetch', 'getiterator'], true)) {
                break;
            }
            $cursor = $stream->nextSignificant($methodClose + 1);
        }

        if ($terminal === null && ($stream->token($cursor ?? -1)['text'] ?? null) === '[') {
            $dimensionEnd = $stream->matchingDelimiter($cursor ?? -1);
            if ($dimensionEnd !== null
                && ($stream->token($stream->nextSignificant($dimensionEnd + 1) ?? -1)['text'] ?? null) !== '=') {
                $terminal = 'fetch';
                $chainEnd = $dimensionEnd;
            }
        }

        if ($terminal === null) {
            if ($this->isExplicitlyConvertedToString($stream, $startIndex, $chainEnd)) {
                $terminal = 'string';
            } elseif ($this->callIsWholeForeachIterable($stream, $startIndex, $chainEnd)) {
                $terminal = 'iteration';
            } elseif ($this->callIsIteratorFunctionArgument($stream, $startIndex, $chainEnd)) {
                $terminal = 'iteration';
            } elseif (($directTerminal = $this->directTerminal($stream, $startIndex, $chainEnd)) !== null) {
                $terminal = $directTerminal;
            } elseif ($implicitTerminal !== null
                && $this->callIsWholeExpression($stream, $startIndex, $chainEnd)) {
                $terminal = $implicitTerminal;
            }
        }

        $endToken = $stream->token($chainEnd);
        if ($terminal === null || $endToken === null) {
            return null;
        }

        return new Call(
            handle: $handle,
            method: $method,
            start: $baseOffset + $start['start'],
            end: $baseOffset + $endToken['end'],
            nameStart: $baseOffset + $literal['start'],
            nameEnd: $baseOffset + $literal['end'],
            parameterWrites: $writes->all(),
            pairedContentState: $pairedContentState,
        );
    }

    /**
     * @param TokenStream $stream
     * @param string $methodName
     * @param string $lower
     * @param list<array{start: int, end: int}> $arguments
     * @param Writes $writes
     * @param 'empty'|'truthy'|'dynamic' $pairedContentState
     * @return 'fetch'|'iteration'|null
     */
    private function applyMethod(
        TokenStream $stream,
        string $methodName,
        string $lower,
        array $arguments,
        Writes $writes,
        string &$pairedContentState,
    ): ?string {
        if ($lower === 'fetch') {
            return 'fetch';
        }

        if ($lower === 'getiterator') {
            return 'iteration';
        }

        if ($lower === 'param') {
            $this->recordParam($stream, $arguments, $writes);

            return null;
        }

        if ($lower === 'params') {
            $this->recordParams($stream, $arguments, $writes);

            return null;
        }

        if ($lower === 'withcontent') {
            $pairedContentState = isset($arguments[0])
                ? $stream->pairedContentKind($arguments[0]['start'], $arguments[0]['end'])
                : 'dynamic';

            return null;
        }

        if ($lower !== 'context') {
            $this->recordMethodParameter($stream, $methodName, $arguments[0] ?? null, $writes);
        }

        return null;
    }

    /** @param list<array{start: int, end: int}> $arguments */
    private function recordParam(TokenStream $stream, array $arguments, Writes $writes): void
    {
        $name = isset($arguments[0])
            ? $stream->literalString($arguments[0]['start'], $arguments[0]['end'])
            : null;
        if ($name === null) {
            $writes->addUnknown();

            return;
        }

        $this->recordParameter($stream, $name['value'], $arguments[1] ?? null, $writes);
    }

    /** @param list<array{start: int, end: int}> $arguments */
    private function recordParams(TokenStream $stream, array $arguments, Writes $writes): void
    {
        if (count($arguments) !== 1
            || ! $this->writeParser->recordArray(
                $stream,
                $arguments[0]['start'],
                $arguments[0]['end'],
                $writes,
            )) {
            $writes->addUnknown();
        }
    }

    /** @param array{start: int, end: int}|null $argument */
    private function recordMethodParameter(
        TokenStream $stream,
        string $methodName,
        ?array $argument,
        Writes $writes,
    ): void {
        $this->recordParameter($stream, Str::snake($methodName), $argument, $writes);
    }

    /** @param array{start: int, end: int}|null $argument */
    private function recordParameter(
        TokenStream $stream,
        string $name,
        ?array $argument,
        Writes $writes,
    ): void {
        $writes->add(
            $name,
            $argument === null
                ? 'truthy'
                : $stream->fluentValueKind($argument['start'], $argument['end']),
            $argument === null
                ? 'true'
                : $this->writeParser->literalParameter($stream, $argument['start'], $argument['end']),
        );
    }

    private function isExplicitlyConvertedToString(TokenStream $stream, int $startIndex, int $endIndex): bool
    {
        $expressionStart = $startIndex;
        $expressionEnd = $endIndex;
        while (true) {
            $before = $stream->previousSignificant($expressionStart - 1);
            $after = $stream->nextSignificant($expressionEnd + 1);
            if ($this->hasStringConversionOperator($stream, $before, $after)) {
                return true;
            }

            if (! $this->isParenthesizedExpression($stream, $before, $after)) {
                return false;
            }

            $functionIndex = $stream->previousSignificant($before - 1);
            if ($this->isStandaloneFunctionNamed($stream, $functionIndex, self::STRING_FUNCTIONS)) {
                return true;
            }

            $expressionStart = $before;
            $expressionEnd = $after;
        }
    }

    private function callIsWholeExpression(TokenStream $stream, int $startIndex, int $endIndex): bool
    {
        $first = $stream->firstSignificant();
        $last = $stream->lastSignificant();
        if ($first === null || $last === null) {
            return false;
        }

        return $this->optionalRangeMatchesCall($stream, $startIndex, $endIndex, $first, $last);
    }

    private function callIsWholeForeachIterable(TokenStream $stream, int $startIndex, int $endIndex): bool
    {
        for ($index = 0; $index < $stream->count(); $index++) {
            if (($stream->token($index)['id'] ?? null) !== T_FOREACH) {
                continue;
            }

            $parentheses = $this->parenthesesAfter($stream, $index);
            if ($parentheses === null) {
                continue;
            }

            [$open, $close] = $parentheses;

            $as = $stream->topLevelToken($open + 1, $close, T_AS);
            if ($as === null) {
                continue;
            }

            $first = $stream->firstSignificant($open + 1, $as);
            $last = $stream->lastSignificant($open + 1, $as);
            if ($this->optionalRangeMatchesCall($stream, $startIndex, $endIndex, $first, $last)) {
                return true;
            }
        }

        return false;
    }

    private function callIsIteratorFunctionArgument(TokenStream $stream, int $startIndex, int $endIndex): bool
    {
        for ($index = 0; $index < $stream->count(); $index++) {
            if (! $this->isStandaloneFunctionNamed($stream, $index, self::ITERATOR_FUNCTIONS)) {
                continue;
            }

            $parentheses = $this->parenthesesAfter($stream, $index);
            if ($parentheses === null) {
                continue;
            }

            [$open, $close] = $parentheses;
            $arguments = $stream->splitTopLevel($open + 1, $close);
            $first = isset($arguments[0])
                ? $stream->firstSignificant($arguments[0]['start'], $arguments[0]['end'])
                : null;
            $last = isset($arguments[0])
                ? $stream->lastSignificant($arguments[0]['start'], $arguments[0]['end'])
                : null;
            if ($this->optionalRangeMatchesCall($stream, $startIndex, $endIndex, $first, $last)) {
                return true;
            }
        }

        return false;
    }

    /** @return 'string'|'iteration'|null */
    private function directTerminal(TokenStream $stream, int $startIndex, int $endIndex): ?string
    {
        $expressionStart = $startIndex;
        $expressionEnd = $endIndex;
        while (true) {
            $before = $stream->previousSignificant($expressionStart - 1);
            $after = $stream->nextSignificant($expressionEnd + 1);
            if (! $this->isParenthesizedExpression($stream, $before, $after)) {
                break;
            }

            $expressionStart = $before;
            $expressionEnd = $after;
        }

        $operator = $stream->token($stream->previousSignificant($expressionStart - 1) ?? -1);

        return match ($operator['id'] ?? null) {
            T_ECHO, T_PRINT => 'string',
            T_YIELD_FROM => 'iteration',
            default => null,
        };
    }

    private function propertyAccessDoesNotFetch(
        TokenStream $stream,
        int $startIndex,
        int $propertyIndex,
        ?int $nextIndex,
    ): bool {
        $next = $stream->token($nextIndex ?? -1);
        if (($next['text'] ?? null) === '='
            || in_array($next['id'] ?? null, [T_COALESCE, T_COALESCE_EQUAL], true)) {
            return true;
        }

        for ($index = 0; $index < $startIndex; $index++) {
            if (! in_array($stream->token($index)['id'] ?? null, [T_EMPTY, T_ISSET, T_UNSET], true)) {
                continue;
            }

            $parentheses = $this->parenthesesAfter($stream, $index);
            if ($parentheses === null) {
                continue;
            }

            [$open, $close] = $parentheses;
            if ($open >= $startIndex
                || $close <= $propertyIndex) {
                continue;
            }

            foreach ($stream->splitTopLevel($open + 1, $close) as $argument) {
                $first = $stream->firstSignificant($argument['start'], $argument['end']);
                if ($first !== null && $this->rangeStartsWithCall($stream, $startIndex, $first)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasStringConversionOperator(TokenStream $stream, ?int $before, ?int $after): bool
    {
        return ($stream->token($before ?? -1)['id'] ?? null) === T_STRING_CAST
            || ($stream->token($before ?? -1)['text'] ?? null) === '.'
            || ($stream->token($after ?? -1)['text'] ?? null) === '.';
    }

    /**
     * @phpstan-assert-if-true int $before
     * @phpstan-assert-if-true int $after
     */
    private function isParenthesizedExpression(TokenStream $stream, ?int $before, ?int $after): bool
    {
        return $before !== null
            && $after !== null
            && ($stream->token($before)['text'] ?? null) === '('
            && $stream->matchingDelimiter($before) === $after;
    }

    /** @param list<string> $names */
    private function isStandaloneFunctionNamed(TokenStream $stream, ?int $index, array $names): bool
    {
        $function = $stream->token($index ?? -1);
        if ($function === null || ! in_array($function['id'], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        $operator = $stream->previousSignificant(($index ?? 0) - 1);

        return in_array(strtolower(ltrim($function['text'], '\\')), $names, true)
            && ! in_array($stream->token($operator ?? -1)['id'] ?? null, self::MEMBER_OPERATORS, true);
    }

    /** @return array{int, int}|null */
    private function parenthesesAfter(TokenStream $stream, int $index): ?array
    {
        $open = $stream->nextSignificant($index + 1);
        if (($stream->token($open ?? -1)['text'] ?? null) !== '(') {
            return null;
        }

        $close = $stream->matchingDelimiter($open ?? -1);

        return $open !== null && $close !== null ? [$open, $close] : null;
    }

    private function rangeStartsWithCall(TokenStream $stream, int $startIndex, int $first): bool
    {
        while (($stream->token($first)['text'] ?? null) === '(') {
            $first = $stream->nextSignificant($first + 1) ?? $first;
        }

        return $first === $startIndex;
    }

    private function callMatchesRange(
        TokenStream $stream,
        int $startIndex,
        int $endIndex,
        int $first,
        int $last,
    ): bool {
        while (($stream->token($first)['text'] ?? null) === '('
            && $stream->matchingDelimiter($first) === $last) {
            $first = $stream->nextSignificant($first + 1) ?? $first;
            $last = $stream->previousSignificant($last - 1) ?? $last;
        }

        return $first === $startIndex && $last === $endIndex;
    }

    private function optionalRangeMatchesCall(
        TokenStream $stream,
        int $startIndex,
        int $endIndex,
        ?int $first,
        ?int $last,
    ): bool {
        return $first !== null
            && $last !== null
            && $this->callMatchesRange($stream, $startIndex, $endIndex, $first, $last);
    }

    /** @param array{id: int, text: string, start: int, end: int} $start */
    private function tagFactoryOpen(TokenStream $stream, int $startIndex, array $start): ?int
    {
        $name = strtolower(ltrim($start['text'], '\\'));
        if (in_array($start['id'], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && $name === 'statamic\\view\\blade\\tag') {
            return $stream->nextSignificant($startIndex + 1);
        }

        if (! in_array($start['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            || ! in_array($name, ['statamic', 'statamic\\statamic'], true)) {
            return null;
        }

        $doubleColon = $stream->nextSignificant($startIndex + 1);
        $tagMethod = $doubleColon === null ? null : $stream->nextSignificant($doubleColon + 1);
        if (($stream->token($doubleColon ?? -1)['id'] ?? null) !== T_DOUBLE_COLON
            || strtolower($stream->token($tagMethod ?? -1)['text'] ?? '') !== 'tag') {
            return null;
        }

        return $tagMethod === null ? null : $stream->nextSignificant($tagMethod + 1);
    }

    /** @return array{source: string, offset: int}|null */
    private function foreachIterable(string $arguments): ?array
    {
        $stream = TokenStream::from($arguments);
        if ($stream === null) {
            return null;
        }

        $start = $stream->firstSignificant();
        $end = $stream->lastSignificant();
        if ($start === null || $end === null) {
            return null;
        }

        if (($stream->token($start)['text'] ?? null) === '('
            && $stream->matchingDelimiter($start) === $end) {
            $start++;
        } else {
            $end++;
        }

        $as = $stream->topLevelToken($start, $end, T_AS);
        if ($as === null) {
            return null;
        }

        $first = $stream->firstSignificant($start, $as);
        $last = $stream->lastSignificant($start, $as);
        if ($first === null || $last === null) {
            return null;
        }

        $firstToken = $stream->token($first);
        $lastToken = $stream->token($last);
        if ($firstToken === null || $lastToken === null) {
            return null;
        }

        return [
            'source' => $stream->sourceBetween($first, $last + 1),
            'offset' => $firstToken['start'],
        ];
    }

    /** @return array{string, string|null} */
    private function splitTagName(string $name): array
    {
        $colon = strpos($name, ':');
        if ($colon === false || $colon === 0) {
            return [$name, null];
        }

        return [substr($name, 0, $colon), substr($name, $colon + 1)];
    }

    /** @param array<string, Call> $calls */
    private function scanFragmentInto(
        array &$calls,
        string $container,
        string $fragment,
        int $containerStart,
        ?string $implicitTerminal,
    ): ?int {
        $offset = strpos($container, $fragment);
        if ($offset === false) {
            return null;
        }

        $start = $containerStart + $offset;
        $this->merge($calls, $this->scanCode($fragment, $start, $implicitTerminal));

        return $start;
    }

    /** @param array<string, Call> $target
     * @param  list<Call>  $calls
     */
    private function merge(array &$target, array $calls): void
    {
        foreach ($calls as $call) {
            $target[$call->start.':'.$call->end] = $call;
        }
    }
}
