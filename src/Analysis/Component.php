<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis;

use Forte\Ast\Elements\ElementNode;

final readonly class Component
{
    public function __construct(
        public ElementNode $node,
        public string $handle,
        public ?string $method,
    ) {}

    public static function from(ElementNode $node): ?self
    {
        $tag = $node->tagNameText();
        $name = match (true) {
            str_starts_with($tag, 'statamic:') => substr($tag, 9),
            str_starts_with($tag, 'statamic-') => substr($tag, 9),
            str_starts_with($tag, 's:') => substr($tag, 2),
            str_starts_with($tag, 's-') => substr($tag, 2),
            default => null,
        };

        if ($name === null || $name === '') {
            return null;
        }

        $colon = strpos($name, ':');
        $dot = str_starts_with(strtolower($name), 'slot.') ? strpos($name, '.') : false;
        $separator = match (true) {
            $colon === false => $dot,
            $dot === false => $colon,
            default => min($colon, $dot),
        };

        return new self(
            $node,
            $separator === false ? $name : substr($name, 0, $separator),
            $separator === false ? null : substr($name, $separator + 1),
        );
    }

    public function isCompilerEmpty(): bool
    {
        return ! $this->node->isPaired()
            && in_array(strtolower($this->handle), ['nav', 'structure', 'children'], true);
    }
}
