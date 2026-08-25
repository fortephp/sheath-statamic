<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Parsing\Antlers\Debug;

use Statamic\View\Antlers\Language\Nodes\AbstractNode;
use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Nodes\ModifierNameNode;
use Statamic\View\Antlers\Language\Nodes\Position;

final class Scanner
{
    /**
     * @param  list<mixed>  $nodes
     * @return list<Reference>
     */
    public function scanNodes(array $nodes, string $source): array
    {
        /** @var array<string, Reference> $references */
        $references = [];
        foreach ($nodes as $node) {
            $this->collect($node, $source, $references);
        }
        ksort($references);

        return array_values($references);
    }

    /** @param array<string, Reference> $references */
    private function collect(mixed $node, string $source, array &$references): void
    {
        if (! $node instanceof AntlersNode) {
            return;
        }

        $tag = $this->debugTag($node);
        if ($tag !== null) {
            $references[$tag->start.':'.$tag->end.':tag'] = $tag;
        }

        foreach ($node->runtimeNodes as $runtimeNode) {
            if (! $runtimeNode instanceof ModifierNameNode
                || ! in_array($runtimeNode->name, ['dump', 'dd', 'ddd'], true)) {
                continue;
            }

            $range = $this->range($runtimeNode);
            if ($range === null) {
                continue;
            }

            [$searchStart, $searchEnd] = $range;
            $start = mb_strpos($source, $runtimeNode->name, $searchStart);
            if ($start === false || $start >= $searchEnd) {
                continue;
            }

            $end = $start + strlen($runtimeNode->name);
            $references[$start.':'.$end.':modifier'] = new Reference(
                'modifier',
                $runtimeNode->name,
                $start,
                $end,
            );
        }

        foreach ($node->children as $child) {
            $this->collect($child, $source, $references);
        }
    }

    private function debugTag(AntlersNode $node): ?Reference
    {
        $identifier = $node->name;
        $name = $identifier?->name;
        if ($identifier === null
            || ! $node->isTagNode
            || ! in_array($name, ['dump', 'dd', 'ddd'], true)) {
            return null;
        }

        $range = $this->range($node);
        if ($range === null) {
            return null;
        }

        return new Reference('tag', $identifier->compound, $range[0], $range[1]);
    }

    /** @return array{int, int}|null */
    private function range(AbstractNode $node): ?array
    {
        $start = $this->validOffset($node->startPosition);
        $end = $this->validOffset($node->endPosition);
        if ($start === null || $end === null || $end < $start) {
            return null;
        }

        return [$start, $end + 1];
    }

    private function validOffset(mixed $position): ?int
    {
        if (! $position instanceof Position || ! is_int($position->offset)) {
            return null;
        }

        return $position->offset >= 0 ? $position->offset : null;
    }
}
