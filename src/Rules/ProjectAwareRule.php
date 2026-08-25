<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules;

use Forte\Sheath\Statamic\Catalog\ProjectCatalog;

abstract class ProjectAwareRule extends RegistryAwareRule
{
    public function cacheContext(array $options): array|string
    {
        return [
            'schema' => 3,
            'registry' => parent::cacheContext($options),
            'project' => $this->projectCacheContext(),
        ];
    }

    public function cacheContextGroup(array $options): string
    {
        return 'fortephp/sheath-statamic:project:'.$this->projectCacheGroup();
    }

    protected function projectCatalog(): ProjectCatalog
    {
        return app(ProjectCatalog::class);
    }

    /** @return array<string, mixed> */
    abstract protected function projectCacheContext(): array;

    abstract protected function projectCacheGroup(): string;
}
