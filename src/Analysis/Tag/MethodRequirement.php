<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Tag;

final class MethodRequirement
{
    /** @var list<string> */
    private const HANDLES = [
        'get_error',
        'scope',
        'section',
        'yield',
        'yields',
    ];

    public static function isMissing(string $handle, ?string $method): bool
    {
        return $method === null && in_array($handle, self::HANDLES, true);
    }
}
