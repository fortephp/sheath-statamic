<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Php;

use Forte\Sheath\Statamic\Analysis\Parameter\Writes;

final class ParameterWriteParser
{
    public function recordArray(TokenStream $stream, int $start, int $end, Writes $writes): bool
    {
        $entries = $stream->arrayEntries($start, $end);
        if ($entries === null) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry['unpack'] || $entry['dynamicKey']) {
                $writes->addUnknown();

                continue;
            }

            if ($entry['key'] === null) {
                continue;
            }

            $writes->add(
                (string) $entry['key'],
                $stream->fluentValueKind($entry['valueStart'], $entry['valueEnd']),
                $this->literalParameter($stream, $entry['valueStart'], $entry['valueEnd']),
            );
        }

        return true;
    }

    public function literalParameter(TokenStream $stream, int $start, int $end): ?string
    {
        $literal = $stream->literalScalar($start, $end);
        if ($literal === null) {
            return null;
        }

        return $literal['type'] === 'null' ? 'true' : $literal['value'];
    }
}
