<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Php;

use PhpToken;
use Throwable;

/** @internal */
final class TokenStream
{
    /** @var list<array{string, int}> */
    private const INTEGER_PATTERNS = [
        ['/^0[xX]([0-9a-fA-F]+)$/', 16],
        ['/^0[bB]([01]+)$/', 2],
        ['/^0[oO]([0-7]+)$/', 8],
        ['/^0([0-7]+)$/', 8],
    ];

    private const PREFIX = '<?php ';

    /** @param list<array{id: int, text: string, start: int, end: int}> $tokens */
    private function __construct(
        private readonly string $source,
        private readonly array $tokens,
    ) {}

    public static function from(string $source): ?self
    {
        try {
            $phpTokens = PhpToken::tokenize(self::PREFIX.$source);
        } catch (Throwable) {
            return null;
        }

        if ($phpTokens === [] || $phpTokens[0]->id !== T_OPEN_TAG) {
            return null;
        }

        $prefixLength = strlen(self::PREFIX);
        $tokens = [];
        foreach (array_slice($phpTokens, 1) as $token) {
            $start = $token->pos - $prefixLength;
            $tokens[] = [
                'id' => $token->id,
                'text' => $token->text,
                'start' => $start,
                'end' => $start + strlen($token->text),
            ];
        }

        return new self($source, $tokens);
    }

    public function count(): int
    {
        return count($this->tokens);
    }

    /** @return array{id: int, text: string, start: int, end: int}|null */
    public function token(int $index): ?array
    {
        return $this->tokens[$index] ?? null;
    }

    public function nextSignificant(int $index): ?int
    {
        for ($count = count($this->tokens); $index < $count; $index++) {
            if (! $this->isTrivia($index)) {
                return $index;
            }
        }

        return null;
    }

    public function previousSignificant(int $index): ?int
    {
        for (; $index >= 0; $index--) {
            if (! $this->isTrivia($index)) {
                return $index;
            }
        }

        return null;
    }

    public function firstSignificant(int $start = 0, ?int $end = null): ?int
    {
        $end ??= count($this->tokens);
        for ($index = $start; $index < $end; $index++) {
            if (! $this->isTrivia($index)) {
                return $index;
            }
        }

        return null;
    }

    public function lastSignificant(int $start = 0, ?int $end = null): ?int
    {
        $end ??= count($this->tokens);
        for ($index = $end - 1; $index >= $start; $index--) {
            if (! $this->isTrivia($index)) {
                return $index;
            }
        }

        return null;
    }

    public function matchingDelimiter(int $openIndex): ?int
    {
        $open = $this->tokens[$openIndex]['text'] ?? null;
        $close = match ($open) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            default => null,
        };

        if ($close === null) {
            return null;
        }

        $depth = 0;
        for ($index = $openIndex, $count = count($this->tokens); $index < $count; $index++) {
            $text = $this->tokens[$index]['text'];
            if ($text === $open) {
                $depth++;
            } elseif ($text === $close && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    public function sourceBetween(int $startIndex, int $endIndex): string
    {
        if ($startIndex >= $endIndex || ! isset($this->tokens[$startIndex])) {
            return '';
        }

        $start = $this->tokens[$startIndex]['start'];
        $end = $this->tokens[$endIndex - 1]['end'] ?? $start;

        return substr($this->source, $start, $end - $start);
    }

    /** @return list<array{start: int, end: int}> */
    public function splitTopLevel(int $startIndex, int $endIndex, string $delimiter = ','): array
    {
        $segments = [];
        $segmentStart = $startIndex;
        $depth = 0;

        for ($index = $startIndex; $index < $endIndex; $index++) {
            $text = $this->tokens[$index]['text'];
            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($text === $delimiter && $depth === 0) {
                if ($this->firstSignificant($segmentStart, $index) !== null) {
                    $segments[] = ['start' => $segmentStart, 'end' => $index];
                }
                $segmentStart = $index + 1;
            }
        }

        if ($this->firstSignificant($segmentStart, $endIndex) !== null) {
            $segments[] = ['start' => $segmentStart, 'end' => $endIndex];
        }

        return $segments;
    }

    public function topLevelToken(int $startIndex, int $endIndex, int|string $needle): ?int
    {
        $depth = 0;
        for ($index = $startIndex; $index < $endIndex; $index++) {
            $token = $this->tokens[$index];
            $text = $token['text'];
            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($depth === 0 && (is_int($needle) ? $token['id'] === $needle : $text === $needle)) {
                return $index;
            }
        }

        return null;
    }

    /** @return array{value: string, start: int, end: int}|null */
    public function literalString(int $startIndex, int $endIndex): ?array
    {
        $first = $this->firstSignificant($startIndex, $endIndex);
        $last = $this->lastSignificant($startIndex, $endIndex);
        if ($first === null || $first !== $last) {
            return null;
        }

        $token = $this->tokens[$first];
        if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING || strlen($token['text']) < 2) {
            return null;
        }

        $raw = $token['text'];
        $body = substr($raw, 1, -1);
        if ($raw[0] === "'") {
            $value = (string) preg_replace_callback(
                '/\\\\([\\\\\'])/',
                static fn (array $match): string => $match[1],
                $body,
            );
        } else {
            $value = stripcslashes($body);
        }

        return ['value' => $value, 'start' => $token['start'], 'end' => $token['end']];
    }

    /** @return array{type: 'string'|'number'|'bool'|'null', value: string, truthy: bool, start: int, end: int}|null */
    public function literalScalar(int $startIndex, int $endIndex): ?array
    {
        $first = $this->firstSignificant($startIndex, $endIndex);
        $last = $this->lastSignificant($startIndex, $endIndex);
        if ($first === null || $last === null) {
            return null;
        }

        while (($this->tokens[$first]['text'] ?? null) === '('
            && $this->matchingDelimiter($first) === $last) {
            $first = $this->nextSignificant($first + 1) ?? $first;
            $last = $this->previousSignificant($last - 1) ?? $last;
        }

        $string = $this->literalString($first, $last + 1);
        if ($string !== null) {
            return [
                'type' => 'string',
                'value' => $string['value'],
                'truthy' => (bool) $string['value'],
                'start' => $string['start'],
                'end' => $string['end'],
            ];
        }

        $sign = '';
        $numberIndex = $first;
        if (in_array($this->tokens[$first]['text'], ['+', '-'], true)) {
            $sign = $this->tokens[$first]['text'];
            $numberIndex = $this->nextSignificant($first + 1) ?? -1;
        }

        if ($numberIndex === $last
            && in_array($this->tokens[$numberIndex]['id'] ?? null, [T_LNUMBER, T_DNUMBER], true)) {
            $number = $this->numericValue($sign.$this->tokens[$numberIndex]['text']);
            if ($number !== null) {
                return [
                    'type' => 'number',
                    'value' => (string) $number,
                    'truthy' => $number != 0,
                    'start' => $this->tokens[$first]['start'],
                    'end' => $this->tokens[$last]['end'],
                ];
            }
        }

        if ($first === $last && $this->tokens[$first]['id'] === T_STRING) {
            $lower = strtolower($this->tokens[$first]['text']);
            if (in_array($lower, ['true', 'false', 'null'], true)) {
                return [
                    'type' => $lower === 'null' ? 'null' : 'bool',
                    'value' => $lower === 'true' ? 'true' : '',
                    'truthy' => $lower === 'true',
                    'start' => $this->tokens[$first]['start'],
                    'end' => $this->tokens[$first]['end'],
                ];
            }
        }

        return null;
    }

    /** @return array{value: int|string|null, dynamic: bool} */
    public function literalArrayKey(int $startIndex, int $endIndex): array
    {
        $string = $this->literalString($startIndex, $endIndex);
        if ($string !== null) {
            $value = $string['value'];
            if (preg_match('/^(?:0|-?[1-9]\d*)$/', $value) === 1) {
                return ['value' => (int) $value, 'dynamic' => false];
            }

            return ['value' => $value, 'dynamic' => false];
        }

        $literal = $this->literalScalar($startIndex, $endIndex);
        if ($literal !== null && $literal['type'] === 'number') {
            return ['value' => (int) $literal['value'], 'dynamic' => false];
        }

        return ['value' => null, 'dynamic' => true];
    }

    /** @return 'empty'|'zero'|'truthy'|'dynamic' */
    public function fluentValueKind(int $startIndex, int $endIndex): string
    {
        $literal = $this->literalScalar($startIndex, $endIndex);
        if ($literal !== null) {
            if ($literal['type'] === 'string') {
                if ($literal['value'] === 'false') {
                    return 'empty';
                }

                $trimmed = trim($literal['value']);

                return match ($trimmed) {
                    '' => 'empty',
                    '0' => 'zero',
                    default => 'truthy',
                };
            }

            if ($literal['type'] === 'null') {
                return 'truthy';
            }

            return $literal['truthy'] ? 'truthy' : ($literal['type'] === 'number' ? 'zero' : 'empty');
        }

        $first = $this->firstSignificant($startIndex, $endIndex);
        $last = $this->lastSignificant($startIndex, $endIndex);
        if ($first === null || $first !== $last) {
            return $this->isEmptyArray($startIndex, $endIndex) ? 'empty' : 'dynamic';
        }

        return 'dynamic';
    }

    /** @return 'empty'|'truthy'|'dynamic' */
    public function pairedContentKind(int $startIndex, int $endIndex): string
    {
        $literal = $this->literalScalar($startIndex, $endIndex);
        if ($literal !== null) {
            return $literal['type'] === 'string' && $literal['value'] === '' ? 'empty' : 'truthy';
        }

        if ($this->arrayEntries($startIndex, $endIndex) !== null) {
            return 'truthy';
        }

        return 'dynamic';
    }

    /**
     * @return list<array{key: int|string|null, keyStart: int|null, keyEnd: int|null, dynamicKey: bool, unpack: bool, valueStart: int, valueEnd: int}>|null
     */
    public function arrayEntries(int $startIndex, int $endIndex): ?array
    {
        $first = $this->firstSignificant($startIndex, $endIndex);
        $last = $this->lastSignificant($startIndex, $endIndex);
        if ($first === null || $last === null) {
            return null;
        }

        $open = null;
        $close = null;
        if ($this->tokens[$first]['text'] === '[' && $this->tokens[$last]['text'] === ']') {
            $open = $first;
            $close = $last;
        } elseif ($this->tokens[$first]['id'] === T_ARRAY) {
            $candidate = $this->nextSignificant($first + 1);
            if ($candidate !== null && $this->tokens[$candidate]['text'] === '(' && $this->matchingDelimiter($candidate) === $last) {
                $open = $candidate;
                $close = $last;
            }
        }

        if ($open === null || $close === null || $this->matchingDelimiter($open) !== $close) {
            return null;
        }

        $entries = [];
        foreach ($this->splitTopLevel($open + 1, $close) as $segment) {
            $segmentFirst = $this->firstSignificant($segment['start'], $segment['end']);
            if ($segmentFirst === null) {
                continue;
            }

            if ($this->tokens[$segmentFirst]['text'] === '...') {
                $entries[] = [
                    'key' => null,
                    'keyStart' => null,
                    'keyEnd' => null,
                    'dynamicKey' => true,
                    'unpack' => true,
                    'valueStart' => $segmentFirst + 1,
                    'valueEnd' => $segment['end'],
                ];

                continue;
            }

            $arrow = $this->topLevelToken($segment['start'], $segment['end'], T_DOUBLE_ARROW);
            if ($arrow === null) {
                $entries[] = [
                    'key' => null,
                    'keyStart' => null,
                    'keyEnd' => null,
                    'dynamicKey' => false,
                    'unpack' => false,
                    'valueStart' => $segment['start'],
                    'valueEnd' => $segment['end'],
                ];

                continue;
            }

            $key = $this->literalArrayKey($segment['start'], $arrow);
            $entries[] = [
                'key' => $key['value'],
                'keyStart' => $segment['start'],
                'keyEnd' => $arrow,
                'dynamicKey' => $key['dynamic'],
                'unpack' => false,
                'valueStart' => $arrow + 1,
                'valueEnd' => $segment['end'],
            ];
        }

        return $entries;
    }

    private function isTrivia(int $index): bool
    {
        return in_array($this->tokens[$index]['id'] ?? null, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    private function isEmptyArray(int $startIndex, int $endIndex): bool
    {
        $entries = $this->arrayEntries($startIndex, $endIndex);

        return $entries === [];
    }

    private function numericValue(string $literal): int|float|null
    {
        $literal = str_replace('_', '', $literal);
        $sign = 1;
        if (str_starts_with($literal, '+') || str_starts_with($literal, '-')) {
            if ($literal[0] === '-') {
                $sign = -1;
            }
            $literal = substr($literal, 1);
        }

        foreach (self::INTEGER_PATTERNS as [$pattern, $base]) {
            if (preg_match($pattern, $literal, $matches) === 1) {
                return $sign * intval($matches[1], $base);
            }
        }

        if (preg_match('/^(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/', $literal) !== 1) {
            return null;
        }

        return $sign * (strpbrk($literal, '.eE') === false ? (int) $literal : (float) $literal);
    }
}
