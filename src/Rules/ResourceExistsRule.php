<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Analysis\Component;
use Forte\Sheath\Statamic\Analysis\Parameter\AttributeSource;
use Forte\Sheath\Statamic\Analysis\Parameter\StaticSource;
use Forte\Sheath\Statamic\Analysis\Resource\Target;
use Forte\Sheath\Statamic\Analysis\Resource\TargetResolver;
use Forte\Sheath\Statamic\Analysis\Tag\Invocation;

#[RequiresPackage('statamic/cms', '^6.0')]
final class ResourceExistsRule extends ProjectAwareRule
{
    use DetectsOpaqueAttributes;

    public function getId(): string
    {
        return 'statamic-resource-exists';
    }

    public function getDescription(): string
    {
        return 'Flags missing literal Statamic project resources.';
    }

    protected function projectCacheContext(): array
    {
        return $this->projectCatalog()->resourceCacheContext();
    }

    protected function projectCacheGroup(): string
    {
        return 'resources';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach ($document->queryComponents() as $node) {
            $component = Component::from($node);
            if ($component === null
                || $component->isCompilerEmpty()
                || $this->elementHasUnmodelledAttributes($node)) {
                continue;
            }

            $paths = $this->explicitAttributeRenderPaths($node);
            if ($paths === null) {
                continue;
            }

            $reported = [];
            foreach ($paths as $path) {
                $source = new AttributeSource($path);
                foreach ($this->missingTargets(
                    $component->handle,
                    $component->method,
                    $source,
                    $node->isPaired(),
                ) as $target) {
                    $key = $target->type->value."\0".$target->handle;
                    if (isset($reported[$key])) {
                        continue;
                    }
                    $reported[$key] = true;

                    $this->reportOpeningTag(
                        $context,
                        $node,
                        $this->missingResourceMessage($target),
                    );
                }
            }
        }

        $this->checkInvocations($document, $context, $this->invocations($document));
    }

    /** @param iterable<Invocation> $invocations */
    private function checkInvocations(Document $document, RuleContext $context, iterable $invocations): void
    {
        foreach ($invocations as $invocation) {
            $missing = $this->missingTargets(
                $invocation->handle,
                $invocation->method,
                $invocation,
                true,
            );
            foreach ($missing as $target) {
                $this->reportRange(
                    $context,
                    $document,
                    $invocation->start,
                    $invocation->end,
                    $this->missingResourceMessage($target),
                );
            }
        }
    }

    /**
     * @return list<Target>
     */
    private function missingTargets(
        string $handle,
        ?string $method,
        StaticSource $parameters,
        bool $resolvesStructuredContent,
    ): array {
        if (! $this->isCoreTag($handle)) {
            return [];
        }

        $missing = [];
        $targets = $this->targetResolver()->resolve(
            $handle,
            $method,
            $parameters,
            $resolvesStructuredContent,
        );
        foreach ($targets ?? [] as $target) {
            $key = $target->type->value."\0".$target->handle;
            if (isset($missing[$key]) || ! $this->targetIsMissing($target)) {
                continue;
            }
            $missing[$key] = $target;
        }

        return array_values($missing);
    }

    private function targetIsMissing(Target $target): bool
    {
        return $target->handle !== ''
            && $this->projectCatalog()->resourceExists($target->type, $target->handle) === false;
    }

    private function missingResourceMessage(Target $target): string
    {
        return "Statamic {$target->type->value} `{$target->handle}` does not exist in this project.";
    }

    private function targetResolver(): TargetResolver
    {
        return app(TargetResolver::class);
    }
}
