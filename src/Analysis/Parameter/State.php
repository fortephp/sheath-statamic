<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Parameter;

final class State
{
    /**
     * @param  list<Write>  $writes
     * @param  list<string>  $aliases
     */
    public static function hasWrite(array $writes, array $aliases): bool
    {
        foreach ($writes as $write) {
            if ($write->name !== null && in_array($write->name, $aliases, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Write>  $writes
     * @param  list<string>  $aliases
     */
    public static function firstStaticFromWrites(array $writes, array $aliases): StaticValue
    {
        [$lastOpaque, $namedWrites] = self::indexWrites($writes);

        foreach ($aliases as $alias) {
            $write = $namedWrites[$alias] ?? null;
            if ($write === null || $write->sequence < $lastOpaque) {
                if ($lastOpaque >= 0) {
                    return StaticValue::unknown();
                }

                continue;
            }

            return $write->literal === null
                ? StaticValue::unknown()
                : StaticValue::literal($write->literal);
        }

        return StaticValue::missing();
    }

    /**
     * @param  list<Write>  $writes
     * @param  list<string>  $aliases
     */
    public static function firstTruthinessFromWrites(array $writes, array $aliases): ?bool
    {
        [$lastOpaque, $namedWrites] = self::indexWrites($writes);

        foreach ($aliases as $alias) {
            $write = $namedWrites[$alias] ?? null;
            if ($write === null || $write->sequence < $lastOpaque) {
                if ($lastOpaque >= 0) {
                    return null;
                }

                continue;
            }

            return match ($write->value) {
                'truthy' => true,
                'zero', 'empty' => false,
                'dynamic' => null,
            };
        }

        return null;
    }

    /**
     * @param  list<Write>  $writes
     * @return array{int, array<string, Write>}
     */
    private static function indexWrites(array $writes): array
    {
        $lastOpaque = -1;
        $namedWrites = [];
        foreach ($writes as $write) {
            if ($write->name === null) {
                $lastOpaque = $write->sequence;
            } else {
                $namedWrites[$write->name] = $write;
            }
        }

        return [$lastOpaque, $namedWrites];
    }
}
