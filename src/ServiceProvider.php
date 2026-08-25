<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic;

use Forte\Parser\ParserOptions;
use Forte\Sheath\SheathManager;
use Forte\Sheath\Statamic\Analysis\Resource\TargetResolver;
use Forte\Sheath\Statamic\Catalog\ProjectCatalog;
use Forte\Sheath\Statamic\Catalog\TagCatalog;
use Forte\Sheath\Statamic\Parsing\Antlers\Debug\Scanner as DebugScanner;
use Forte\Sheath\Statamic\Parsing\Antlers\Regions\IgnoredRegionProvider;
use Forte\Sheath\Statamic\Parsing\Antlers\Regions\Parser as RegionParser;
use Forte\Sheath\Statamic\Parsing\Antlers\Regions\Scanner as RegionScanner;
use Forte\Sheath\Statamic\Parsing\Fluent\CallScanner;
use Forte\Sheath\Statamic\Parsing\TagsDirective\Scanner as TagsDirectiveScanner;
use Forte\Sheath\Statamic\Rules\Antlers\RegionPairsRule;
use Forte\Sheath\Statamic\Rules\Antlers\RegionSyntaxRule;
use Forte\Sheath\Statamic\Rules\AssetsPathRequiresContainerRule;
use Forte\Sheath\Statamic\Rules\CollectionMethodCompatibilityRule;
use Forte\Sheath\Statamic\Rules\DirectiveArgumentsRule;
use Forte\Sheath\Statamic\Rules\Fluent\ExistsRule as FluentExistsRule;
use Forte\Sheath\Statamic\Rules\Fluent\MethodExistsRule as FluentMethodExistsRule;
use Forte\Sheath\Statamic\Rules\Fluent\RequiredParametersRule as FluentRequiredParametersRule;
use Forte\Sheath\Statamic\Rules\IncompatibleQueryParametersRule;
use Forte\Sheath\Statamic\Rules\NoDebugTagsRule;
use Forte\Sheath\Statamic\Rules\PartialExistsRule;
use Forte\Sheath\Statamic\Rules\ResourceExistsRule;
use Forte\Sheath\Statamic\Rules\SpecialChildPlacementRule;
use Forte\Sheath\Statamic\Rules\Tag\ExistsRule as TagExistsRule;
use Forte\Sheath\Statamic\Rules\Tag\MethodExistsRule as TagMethodExistsRule;
use Forte\Sheath\Statamic\Rules\Tag\PairRequiredRule as TagPairRequiredRule;
use Forte\Sheath\Statamic\Rules\Tag\RequiredParameterRule;
use Forte\Sheath\Statamic\Rules\TagsDirective\ExistsRule as TagsDirectiveExistsRule;
use Forte\Sheath\Statamic\Rules\TagsDirective\RequiredParametersRule as TagsDirectiveRequiredParametersRule;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    /** @var list<class-string> */
    public const RULES = [
        RegionPairsRule::class,
        RegionSyntaxRule::class,
        TagExistsRule::class,
        TagMethodExistsRule::class,
        TagPairRequiredRule::class,
        RequiredParameterRule::class,
        SpecialChildPlacementRule::class,
        DirectiveArgumentsRule::class,
        IncompatibleQueryParametersRule::class,
        AssetsPathRequiresContainerRule::class,
        PartialExistsRule::class,
        ResourceExistsRule::class,
        CollectionMethodCompatibilityRule::class,
        FluentExistsRule::class,
        FluentMethodExistsRule::class,
        FluentRequiredParametersRule::class,
        TagsDirectiveExistsRule::class,
        TagsDirectiveRequiredParametersRule::class,
        NoDebugTagsRule::class,
    ];

    /** @var array<string, string> */
    public const PRESET = [
        'statamic-antlers-region-pairs' => 'error',
        'statamic-antlers-region-syntax' => 'error',
        'statamic-tag-exists' => 'error',
        'statamic-tag-method-exists' => 'error',
        'statamic-tag-pair-required' => 'error',
        'statamic-required-tag-parameter' => 'error',
        'statamic-special-child-placement' => 'error',
        'statamic-directive-arguments' => 'error',
        'statamic-incompatible-query-parameters' => 'error',
        'statamic-assets-path-requires-container' => 'error',
        'statamic-partial-exists' => 'error',
        'statamic-resource-exists' => 'error',
        'statamic-collection-method-compatibility' => 'error',
        'statamic-fluent-tag-exists' => 'error',
        'statamic-fluent-tag-method-exists' => 'error',
        'statamic-fluent-required-parameters' => 'error',
        'statamic-tags-directive-tag-exists' => 'error',
        'statamic-tags-directive-required-parameters' => 'error',
        'statamic-no-debug-tags' => 'error',
    ];

    public function register(): void
    {
        $this->app->singleton(RegionScanner::class);
        $this->app->singleton(RegionParser::class);
        $this->app->singleton(DebugScanner::class);
        $this->app->singleton(IgnoredRegionProvider::class);
        $this->app->singleton(CallScanner::class);
        $this->app->singleton(TagsDirectiveScanner::class);
        $this->app->singleton(TargetResolver::class);
        $this->app->scoped(TagCatalog::class);
        $this->app->scoped(ProjectCatalog::class);
    }

    public function boot(SheathManager $sheath, ParserOptions $parserOptions): void
    {
        foreach (['s:', 's-', 'statamic:', 'statamic-'] as $prefix) {
            $parserOptions->withComponentPrefix($prefix);
        }

        foreach (['tags', 'cascade', 'frontmatter', 'recursive_children', 'nocache'] as $directive) {
            $parserOptions->getDirectives()->registerDirective($directive);
        }

        $sheath
            ->registerRules(self::RULES)
            ->registerIgnoredRegionProvider(IgnoredRegionProvider::class)
            ->registerPreset('statamic', self::PRESET);
    }
}
