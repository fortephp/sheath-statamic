<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Antlers\Regions;

use Forte\Ast\Document\Document;
use Statamic\View\Antlers\Language\Analyzers\NodeTypeAnalyzer;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Statamic\View\Antlers\Language\Runtime\EnvironmentDetails;
use Throwable;
use WeakMap;

/** @internal */
final class Parser
{
    /** @var WeakMap<Document, array<string, ParseResult>> */
    private WeakMap $results;

    public function __construct()
    {
        $this->results = new WeakMap;
    }

    public function parse(Document $document, Region $region, string $body): ParseResult
    {
        $key = $region->contentStartOffset.':'.$region->contentEndOffset;
        $documentResults = $this->results[$document] ?? [];
        if (isset($documentResults[$key])) {
            return $documentResults[$key];
        }

        try {
            // DocumentParser reads this static directly. Resolving it mirrors
            // Statamic's runtime bootstrap without rendering.
            NodeTypeAnalyzer::$environmentDetails = app(EnvironmentDetails::class);
            $result = ParseResult::success(array_values((new DocumentParser)->parse($body)));
        } catch (Throwable $exception) {
            $result = ParseResult::failure($exception);
        }

        $documentResults[$key] = $result;
        $this->results[$document] = $documentResults;

        return $result;
    }
}
