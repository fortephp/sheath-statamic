<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Component;

use Forte\Ast\Elements\Attribute;
use Forte\Sheath\Statamic\Analysis\Parameter\StaticValue;

final class Parameters
{
    /**
     * @param  list<Attribute>  $attributes
     * @return array<string, Attribute>
     */
    public static function namedFromAttributes(array $attributes): array
    {
        $parameters = [];

        foreach ($attributes as $attribute) {
            if ($attribute->isBladeConstruct() || $attribute->hasComplexName()) {
                continue;
            }

            $name = strtolower(ltrim($attribute->nameText(), ':'));
            if ($name !== '') {
                $parameters[$name] = $attribute;
            }
        }

        return $parameters;
    }

    /**
     * @param  list<Attribute>  $attributes
     * @param  list<string>  $names
     */
    public static function firstStaticValueFromAttributes(array $attributes, array $names): StaticValue
    {
        $parameters = self::namedFromAttributes($attributes);

        foreach ($names as $name) {
            $attribute = $parameters[$name] ?? null;
            if ($attribute === null) {
                continue;
            }

            if ($attribute->valueText() === null) {
                return StaticValue::literal('true');
            }

            if ($attribute->isDynamic() || $attribute->hasComplexValue()) {
                return StaticValue::unknown();
            }

            $value = $attribute->decodedValueText();

            return $value === null
                ? StaticValue::unknown()
                : StaticValue::literal($value);
        }

        return StaticValue::missing();
    }
}
