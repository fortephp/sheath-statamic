<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Parameter;

use Forte\Ast\Elements\Attribute;
use Forte\Sheath\Statamic\Analysis\Component\Parameters;
use Forte\Sheath\Statamic\Parsing\Php\TokenStream;

final readonly class AttributeSource implements StaticSource
{
    /** @param list<Attribute> $attributes */
    public function __construct(private array $attributes) {}

    public function hasParameter(array $aliases): bool
    {
        return $this->firstAliasAttribute($aliases) !== null;
    }

    public function firstStaticParameter(array $aliases): StaticValue
    {
        $boundExpression = $this->simpleBoundExpression($this->firstAliasAttribute($aliases));
        if ($boundExpression !== null) {
            $literal = $this->boundLiteral($boundExpression);

            return $literal === null ? StaticValue::unknown() : StaticValue::literal($literal['value']);
        }

        return Parameters::firstStaticValueFromAttributes($this->attributes, $aliases);
    }

    public function firstStaticTruthiness(array $aliases): ?bool
    {
        $boundExpression = $this->simpleBoundExpression($this->firstAliasAttribute($aliases));
        if ($boundExpression !== null) {
            return $this->boundLiteral($boundExpression)['truthy'] ?? null;
        }

        $value = $this->firstStaticParameter($aliases);

        if (! $value->known || $value->value === null) {
            return null;
        }

        return $value->value !== 'false' && (bool) $value->value;
    }

    /** @param list<string> $aliases */
    private function firstAliasAttribute(array $aliases): ?Attribute
    {
        $parameters = Parameters::namedFromAttributes($this->attributes);

        foreach ($aliases as $alias) {
            if (isset($parameters[$alias])) {
                return $parameters[$alias];
            }
        }

        return null;
    }

    private function simpleBoundExpression(?Attribute $attribute): ?string
    {
        if ($attribute === null || ! $attribute->isDynamic() || $attribute->hasComplexValue()) {
            return null;
        }

        return $attribute->valueText();
    }

    /** @return array{value: string, truthy: bool}|null */
    private function boundLiteral(string $expression): ?array
    {
        $stream = TokenStream::from($expression);
        if ($stream === null) {
            return null;
        }

        $literal = $stream->literalScalar(0, $stream->count());

        if ($literal === null) {
            return null;
        }

        return [
            'value' => $literal['value'],
            'truthy' => $literal['type'] === 'string' && $literal['value'] === 'false'
                ? false
                : $literal['truthy'],
        ];
    }
}
