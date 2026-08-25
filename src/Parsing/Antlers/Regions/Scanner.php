<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Antlers\Regions;

final class Scanner
{
    public const OPEN = '@antlers';

    public const CLOSE = '@endantlers';

    private ?string $cachedSource = null;

    private ?Scan $cachedScan = null;

    public function scan(string $source): Scan
    {
        if ($this->cachedScan !== null && $this->cachedSource === $source) {
            return $this->cachedScan;
        }

        preg_match_all('/@endantlers|@antlers/', $source, $matches, PREG_OFFSET_CAPTURE);

        $regions = [];
        /** @var array<int, Issue> $issueEvents */
        $issueEvents = [];
        /** @var array{start: int, issue: int, nested: list<int>}|null $active */
        $active = null;

        foreach ($matches[0] as $ordinal => [$token, $offset]) {
            if ($token === self::OPEN) {
                $issueEvents[$ordinal] = new Issue(
                    'unclosed-open',
                    $offset,
                    $offset + strlen(self::OPEN),
                );

                if ($active === null) {
                    $active = ['start' => $offset, 'issue' => $ordinal, 'nested' => []];
                } else {
                    $active['nested'][] = $ordinal;
                }

                continue;
            }

            if ($active === null) {
                $issueEvents[$ordinal] = new Issue(
                    'orphan-close',
                    $offset,
                    $offset + strlen(self::CLOSE),
                );

                continue;
            }

            unset($issueEvents[$active['issue']]);
            foreach ($active['nested'] as $nestedOrdinal) {
                $nested = $issueEvents[$nestedOrdinal];
                $issueEvents[$nestedOrdinal] = new Issue(
                    'nested',
                    $nested->startOffset,
                    $nested->endOffset,
                );
            }

            $contentStartOffset = $active['start'] + strlen(self::OPEN);
            $regions[] = new Region(
                $active['start'],
                $contentStartOffset,
                $offset,
                $offset + strlen(self::CLOSE),
                $active['nested'] !== [],
            );
            $active = null;
        }

        $this->cachedSource = $source;

        return $this->cachedScan = new Scan($regions, array_values($issueEvents));
    }
}
