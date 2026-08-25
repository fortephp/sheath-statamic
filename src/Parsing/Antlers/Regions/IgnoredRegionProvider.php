<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Antlers\Regions;

use Forte\Sheath\Contracts\IgnoredRegionProvider as IgnoredRegionProviderContract;
use Forte\Sheath\Parsing\IgnoredRegion;

final readonly class IgnoredRegionProvider implements IgnoredRegionProviderContract
{
    public function __construct(private Scanner $scanner) {}

    public function id(): string
    {
        return 'statamic-antlers';
    }

    public function regions(string $source, string $filePath): iterable
    {
        foreach ($this->scanner->scan($source)->regions as $region) {
            yield new IgnoredRegion($region->startOffset, $region->endOffset);
        }
    }

    /** @return array{schema: int, open: string, close: string} */
    public function cacheContext(): array
    {
        return ['schema' => 1, 'open' => Scanner::OPEN, 'close' => Scanner::CLOSE];
    }
}
