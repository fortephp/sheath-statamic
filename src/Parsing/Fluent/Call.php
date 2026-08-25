<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Fluent;

use Forte\Sheath\Statamic\Analysis\Parameter\Write;
use Forte\Sheath\Statamic\Analysis\Tag\Invocation;

final readonly class Call extends Invocation
{
    /**
     * @param  list<Write>  $parameterWrites
     */
    public function __construct(
        string $handle,
        ?string $method,
        int $start,
        int $end,
        public int $nameStart,
        public int $nameEnd,
        array $parameterWrites,
        public string $pairedContentState,
    ) {
        parent::__construct($handle, $method, $start, $end, $parameterWrites);
    }
}
