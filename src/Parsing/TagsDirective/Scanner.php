<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\TagsDirective;

use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Statamic\Analysis\Parameter\Write;
use Forte\Sheath\Statamic\Analysis\Parameter\Writes;
use Forte\Sheath\Statamic\Parsing\Php\ParameterWriteParser;
use Forte\Sheath\Statamic\Parsing\Php\TokenStream;
use WeakMap;

final class Scanner
{
    /** @var WeakMap<Document, array{definitions: list<Definition>, issues: list<Issue>}> */
    private WeakMap $cache;

    public function __construct(private readonly ParameterWriteParser $writeParser)
    {
        $this->cache = new WeakMap;
    }

    /** @return list<Definition> */
    public function scan(Document $document): array
    {
        return $this->result($document)['definitions'];
    }

    /** @return list<Issue> */
    public function issues(Document $document): array
    {
        return $this->result($document)['issues'];
    }

    /** @return array{definitions: list<Definition>, issues: list<Issue>} */
    private function result(Document $document): array
    {
        if (isset($this->cache[$document])) {
            return $this->cache[$document];
        }

        $tags = [];
        $issues = [];
        foreach ($document->allOfType(DirectiveNode::class, true) as $directive) {
            if (strtolower($directive->nameText()) !== 'tags' || $directive->arguments() === null) {
                continue;
            }

            $arguments = $directive->arguments();
            $raw = $directive->getDocumentContent();
            $relative = strpos($raw, $arguments);
            if ($relative === false) {
                continue;
            }

            $result = $this->scanArguments($arguments, $directive->startOffset() + $relative);
            array_push($tags, ...$result['definitions']);
            array_push($issues, ...$result['issues']);
        }

        return $this->cache[$document] = ['definitions' => $tags, 'issues' => $issues];
    }

    /** @return array{definitions: list<Definition>, issues: list<Issue>} */
    private function scanArguments(string $arguments, int $baseOffset): array
    {
        $stream = TokenStream::from($arguments);
        if ($stream === null) {
            return ['definitions' => [], 'issues' => []];
        }

        $start = $stream->firstSignificant();
        $end = $stream->lastSignificant();
        if ($start === null || $end === null) {
            return ['definitions' => [], 'issues' => []];
        }

        if (($stream->token($start)['text'] ?? null) === '('
            && $stream->matchingDelimiter($start) === $end) {
            $start++;
        } else {
            $end++;
        }

        $literal = $stream->literalString($start, $end);
        if ($literal !== null) {
            return ['definitions' => [$this->literalTag($literal, $baseOffset)], 'issues' => []];
        }

        $entries = $stream->arrayEntries($start, $end);
        if ($entries === null) {
            $scalar = $stream->literalScalar($start, $end);

            return $scalar !== null && $scalar['type'] !== 'null'
                ? ['definitions' => [], 'issues' => [new Issue($baseOffset + $scalar['start'], $baseOffset + $scalar['end'])]]
                : ['definitions' => [], 'issues' => []];
        }

        $resolved = $this->resolveOuterDefinitions($stream, $entries, $baseOffset);
        if ($resolved === null) {
            return ['definitions' => [], 'issues' => []];
        }

        $definitions = [];
        $issues = [];
        foreach ($resolved as $definition) {
            if ($definition instanceof Issue) {
                $issues[] = $definition;
            } else {
                $definitions[] = $this->literalTag($definition, $baseOffset);
            }
        }

        return ['definitions' => $definitions, 'issues' => $issues];
    }

    /**
     * @param  list<array{key: int|string|null, keyStart: int|null, keyEnd: int|null, dynamicKey: bool, unpack: bool, valueStart: int, valueEnd: int}>  $entries
     * @return array<string, array{value: string, start: int, end: int, parameterWrites?: list<Write>}|Issue>|null
     */
    private function resolveOuterDefinitions(TokenStream $stream, array $entries, int $baseOffset): ?array
    {
        $resolved = [];
        $nextNumeric = 0;
        foreach ($entries as $entry) {
            if ($entry['dynamicKey'] || $entry['unpack']) {
                return null;
            }

            $key = $entry['key'];
            if ($key === null) {
                $key = $nextNumeric++;
            } elseif (is_int($key) && $key >= $nextNumeric) {
                $nextNumeric = $key + 1;
            }

            $definition = $stream->literalString($entry['valueStart'], $entry['valueEnd']);
            if ($definition === null) {
                $nested = $stream->arrayEntries($entry['valueStart'], $entry['valueEnd']);
                if ($nested === null) {
                    $scalar = $stream->literalScalar($entry['valueStart'], $entry['valueEnd']);
                    if ($scalar === null) {
                        return null;
                    }

                    $definition = new Issue(
                        $baseOffset + $scalar['start'],
                        $baseOffset + $scalar['end'],
                    );
                } elseif ($nested === []) {
                    $definition = $this->issueForRange(
                        $stream,
                        $entry['valueStart'],
                        $entry['valueEnd'],
                        $baseOffset,
                    );
                } else {
                    $definition = $this->firstNestedDefinition($stream, $nested, $baseOffset);
                    if ($definition === null) {
                        return null;
                    }
                }
            }

            $resolved[(string) $key] = $definition;
        }

        return $resolved;
    }

    private function issueForRange(TokenStream $stream, int $start, int $end, int $baseOffset): Issue
    {
        $first = $stream->firstSignificant($start, $end);
        $last = $stream->lastSignificant($start, $end);
        $startToken = $stream->token($first ?? -1);
        $endToken = $stream->token($last ?? -1);

        return new Issue(
            $baseOffset + ($startToken['start'] ?? 0),
            $baseOffset + ($endToken['end'] ?? ($startToken['end'] ?? 0)),
        );
    }

    /**
     * @param  list<array{key: int|string|null, keyStart: int|null, keyEnd: int|null, dynamicKey: bool, unpack: bool, valueStart: int, valueEnd: int}>  $entries
     * @return array{value: string, start: int, end: int, parameterWrites: list<Write>}|Issue|null
     */
    private function firstNestedDefinition(TokenStream $stream, array $entries, int $baseOffset): array|Issue|null
    {
        if ($entries === []) {
            return null;
        }

        $resolved = [];
        $nextNumeric = 0;
        foreach ($entries as $entry) {
            if ($entry['dynamicKey'] || $entry['unpack']) {
                return null;
            }

            $key = $entry['key'];
            if ($key === null) {
                $key = $nextNumeric++;
                $valueStart = $stream->firstSignificant($entry['valueStart'], $entry['valueEnd']);
                $valueEnd = $stream->lastSignificant($entry['valueStart'], $entry['valueEnd']);
                if ($valueStart === null || $valueEnd === null) {
                    return null;
                }
                $startToken = $stream->token($valueStart);
                $endToken = $stream->token($valueEnd);
                if ($startToken === null || $endToken === null) {
                    return null;
                }
                $parameterWrites = $this->parameterWrites($stream, $entry['valueStart'], $entry['valueEnd']);
                if ($parameterWrites === false) {
                    return $this->issueForRange(
                        $stream,
                        $entry['valueStart'],
                        $entry['valueEnd'],
                        $baseOffset,
                    );
                }

                $literal = [
                    'value' => (string) $key,
                    'start' => $startToken['start'],
                    'end' => $endToken['end'],
                    'parameterWrites' => $parameterWrites,
                ];
            } else {
                if (is_int($key) && $key >= $nextNumeric) {
                    $nextNumeric = $key + 1;
                }

                if ($entry['keyStart'] === null || $entry['keyEnd'] === null) {
                    return null;
                }
                $string = $stream->literalString($entry['keyStart'], $entry['keyEnd']);
                if ($string !== null) {
                    $parameterWrites = $this->parameterWrites($stream, $entry['valueStart'], $entry['valueEnd']);
                    if ($parameterWrites === false) {
                        return $this->issueForRange(
                            $stream,
                            $entry['valueStart'],
                            $entry['valueEnd'],
                            $baseOffset,
                        );
                    }

                    $literal = $string + [
                        'parameterWrites' => $parameterWrites,
                    ];
                } else {
                    $keyStart = $stream->firstSignificant($entry['keyStart'], $entry['keyEnd']);
                    $keyEnd = $stream->lastSignificant($entry['keyStart'], $entry['keyEnd']);
                    $token = $keyStart === null ? null : $stream->token($keyStart);
                    $endToken = $keyEnd === null ? null : $stream->token($keyEnd);
                    if ($token === null || $endToken === null) {
                        return null;
                    }
                    $literal = [
                        'value' => (string) $key,
                        'start' => $token['start'],
                        'end' => $endToken['end'],
                        'parameterWrites' => [Writes::unknown()],
                    ];
                }
            }

            $resolved[(string) $key] = $literal;
        }

        return reset($resolved);
    }

    /** @return list<Write>|false */
    private function parameterWrites(TokenStream $stream, int $start, int $end): array|false
    {
        $writes = new Writes;
        if (! $this->writeParser->recordArray($stream, $start, $end, $writes)) {
            if ($stream->literalScalar($start, $end) !== null) {
                return false;
            }

            return [Writes::unknown()];
        }

        return $writes->all();
    }

    /**
     * @param  array{value: string, start: int, end: int, parameterWrites?: list<Write>}  $literal
     */
    private function literalTag(array $literal, int $baseOffset): Definition
    {
        $colon = strpos($literal['value'], ':');
        if ($colon === false || $colon === 0) {
            $handle = $literal['value'];
            $method = null;
        } else {
            $handle = substr($literal['value'], 0, $colon);
            $method = substr($literal['value'], $colon + 1);
        }

        return new Definition(
            handle: $handle,
            method: $method,
            start: $baseOffset + $literal['start'],
            end: $baseOffset + $literal['end'],
            parameterWrites: $literal['parameterWrites'] ?? [],
        );
    }
}
