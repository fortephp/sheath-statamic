<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Analysis\Component;
use Forte\Sheath\Statamic\Analysis\Tag\Invocation;
use Forte\Sheath\Statamic\Parsing\Antlers\Debug\Scanner as DebugScanner;
use Forte\Sheath\Statamic\Parsing\Antlers\Regions\Parser as RegionParser;
use Forte\Sheath\Statamic\Parsing\Antlers\Regions\Scanner as RegionScanner;
use Statamic\Tags\Dd;
use Statamic\Tags\Dump;

#[RequiresPackage('statamic/cms', '^6.0')]
final class NoDebugTagsRule extends RegistryAwareRule
{
    /** @return array{registry: array<string, mixed>|string, debug_modifiers: array<string, mixed>} */
    public function cacheContext(array $options): array
    {
        return [
            'registry' => parent::cacheContext($options),
            'debug_modifiers' => $this->catalog()->debugModifierCacheContext(),
        ];
    }

    public function cacheContextGroup(array $options): string
    {
        return 'fortephp/sheath-statamic:debug';
    }

    public function getId(): string
    {
        return 'statamic-no-debug-tags';
    }

    public function getDescription(): string
    {
        return 'Flags Statamic `dump`, `dd`, and `ddd` calls in templates.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        $this->checkAntlersRegions($document, $context);

        foreach ($document->queryComponents() as $node) {
            $component = Component::from($node);
            if ($component === null || ! $this->isExecutableDebugTag($component->handle, $component->method)) {
                continue;
            }

            $this->reportOpeningTag(
                $context,
                $node,
                $this->debugTagMessage($this->displayName($component->handle, $component->method)),
            );
        }

        $this->checkInvocations($context, $document, $this->invocations($document));
    }

    /** @param iterable<Invocation> $invocations */
    private function checkInvocations(RuleContext $context, Document $document, iterable $invocations): void
    {
        foreach ($invocations as $invocation) {
            if (! $this->isExecutableDebugTag($invocation->handle, $invocation->method)) {
                continue;
            }

            $this->reportRange(
                $context,
                $document,
                $invocation->start,
                $invocation->end,
                $this->debugTagMessage($invocation->displayName()),
            );
        }
    }

    private function checkAntlersRegions(Document $document, RuleContext $context): void
    {
        $source = $context->getOriginalSource();
        $regions = app(RegionScanner::class)->scan($source)->regions;

        foreach ($regions as $region) {
            if ($region->nested) {
                continue;
            }

            $body = substr($source, $region->contentStartOffset, $region->contentEndOffset - $region->contentStartOffset);
            $parsed = app(RegionParser::class)->parse($document, $region, $body);
            if ($parsed->failure !== null) {
                continue;
            }

            foreach (app(DebugScanner::class)->scanNodes($parsed->nodes, $body) as $reference) {
                if (! $this->isDebugReference($reference->kind, $reference->name)) {
                    continue;
                }

                $start = $region->contentStartOffset + $this->characterOffsetToByteOffset($body, $reference->start);
                $end = $region->contentStartOffset + $this->characterOffsetToByteOffset($body, $reference->end);
                $context->reportAt(
                    $this->originalPosition($source, $start),
                    $this->originalPosition($source, $end),
                    "Remove debug {$reference->kind} `{$reference->name}`. It can expose request data or stop rendering.",
                );
            }
        }
    }

    private function isExecutableDebugTag(string $handle, ?string $method): bool
    {
        if (! in_array($handle, ['dump', 'dd', 'ddd'], true)) {
            return false;
        }

        if ($this->catalog()->acceptsMethod($handle, $method) !== true) {
            return false;
        }

        return $this->catalog()->isA($handle, Dump::class)
            || $this->catalog()->isA($handle, Dd::class);
    }

    private function isDebugReference(string $kind, string $name): bool
    {
        if ($kind === 'modifier') {
            return $this->catalog()->isCoreDebugModifier($name);
        }

        $separator = strpos($name, ':');
        $handle = $separator === false ? $name : substr($name, 0, $separator);
        $method = $separator === false ? null : substr($name, $separator + 1);

        return $this->isExecutableDebugTag($handle, $method);
    }

    private function debugTagMessage(string $name): string
    {
        return "Remove debug tag `{$name}`. It can expose request data or stop rendering.";
    }

    private function displayName(string $handle, ?string $method): string
    {
        return $method === null ? $handle : $handle.':'.$method;
    }
}
