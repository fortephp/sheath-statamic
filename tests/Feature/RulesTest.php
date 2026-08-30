<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\SheathManager;
use Forte\Sheath\Statamic\Tests\Fixtures\FixtureTag;
use Statamic\Tags\Concerns\GetsQueryResults;
use Statamic\Tags\Tags;
use Statamic\View\Antlers\Language\Analyzers\NodeTypeAnalyzer;

it('reports every structural Antlers marker failure at the offending delimiter', function (string $source, int $count): void {
    $violations = lintStatamic('statamic-antlers-region-pairs', $source);
    preg_match_all('/@endantlers|@antlers/', $source, $matches, PREG_OFFSET_CAPTURE);
    $markerRanges = array_map(
        static fn (array $match): array => [$match[1], $match[1] + strlen($match[0])],
        $matches[0],
    );

    expect($violations)->toHaveCount($count);
    foreach ($violations as $violation) {
        expect([$violation->start->offset, $violation->end->offset])->toBeIn($markerRanges);
    }
})->with([
    'unclosed' => ['@antlers {{ title }}', 1],
    'orphan close' => ['{{ title }} @endantlers', 1],
    'nested' => ['@antlers {{ title }} @antlers {{ subtitle }} @endantlers', 1],
    'nested with extra close' => ['@antlers {{ title }} @antlers {{ subtitle }} @endantlers @endantlers', 2],
    'balanced' => ['@antlers {{ title }} @endantlers', 0],
]);

it('validates only structurally sound Antlers bodies', function (): void {
    expect(lintStatamic('statamic-antlers-region-syntax', '@antlers {{ if }} @endantlers'))->toHaveCount(1)
        ->and(lintStatamic('statamic-antlers-region-syntax', '@antlers {{ title }} @endantlers'))->toHaveCount(0)
        ->and(lintStatamic('statamic-antlers-region-syntax', '@antlers @antlers {{ if }} @endantlers @endantlers'))->toHaveCount(0);
});

it('initializes the Antlers parser environment before validating a region', function (): void {
    $environment = NodeTypeAnalyzer::$environmentDetails;
    NodeTypeAnalyzer::$environmentDetails = null;

    try {
        $valid = "@antlers\n    Hello - {{ world }}\n@endantlers";

        expect(lintStatamic('statamic-antlers-region-syntax', $valid))->toHaveCount(0)
            ->and(lintStatamic('statamic-antlers-region-syntax', '@antlers {{ if }} @endantlers'))->toHaveCount(1);
    } finally {
        NodeTypeAnalyzer::$environmentDetails = $environment;
    }
});

it('skips unexpected Antlers parser failures that do not establish invalid syntax', function (): void {
    $source = '@antlers({{@endantlers';
    $violations = lintStatamic('statamic-antlers-region-syntax', $source);

    expect($violations)->toHaveCount(0);
});

it('uses the inclusive Antlers parser end offset for exact ASCII and UTF-8 ranges', function (string $source, int $startCharacter): void {
    $violation = lintStatamic('statamic-antlers-region-syntax', $source)[0];
    $expectedStart = templateOffset($source, '{{ if }}');

    expect($violation->start->offset)->toBe($expectedStart)
        ->and($violation->end->offset)->toBe($expectedStart + strlen('{{ if }}'))
        ->and($violation->start->line)->toBe(1)
        ->and($violation->end->line)->toBe(1)
        ->and($violation->start->character)->toBe($startCharacter)
        ->and($violation->end->character)->toBe($startCharacter + 8);
})->with([
    'ascii' => ['@antlers {{ if }} @endantlers', 10],
    'utf8 prefix' => ['@antlers é {{ if }} @endantlers', 12],
]);

it('uses the live Statamic registry for tag handles', function (): void {
    expect(lintStatamic('statamic-tag-exists', '<s:definitely_missing/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-exists', '<s:fixture/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-exists', '<statamic:fixture/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-exists', '<s:slot:name/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-exists', '<s:slot.name/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-exists', '<s:no_results/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-exists', '<s:fixture.known_method/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-exists', '<s:partial.foo/>'))->toHaveCount(1);
});

it('does not apply displaced core contracts to replacement tag implementations', function (): void {
    $tags = statamicRegistry('statamic.tags');

    $tags->put('collection', FixtureTag::class);
    expect(lintStatamic('statamic-required-tag-parameter', '<s:collection/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-fluent-required-parameters', "{{ Statamic::tag('collection') }}"))->toHaveCount(0)
        ->and(lintStatamic('statamic-tags-directive-required-parameters', "@tags('collection')"))->toHaveCount(0)
        ->and(lintStatamic('statamic-incompatible-query-parameters', '<s:collection paginate="2" limit="2"/>'))->toHaveCount(0);

    $tags->put('scope', FixtureTag::class);
    expect(lintStatamic('statamic-tag-method-exists', '<s:scope/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-fluent-tag-method-exists', "{{ Statamic::tag('scope') }}"))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-pair-required', '<s:scope/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-pair-required', "{{ Statamic::tag('scope') }}"))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-pair-required', "@tags('scope')"))->toHaveCount(0);

    $tags->put('assets', FixtureTag::class);
    expect(lintStatamic('statamic-assets-path-requires-container', '<s:assets path="photos"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-resource-exists', '<s:assets container="missing"/>'))->toHaveCount(0);

    $tags->put('partial', FixtureTag::class);
    expect(lintStatamic('statamic-tag-method-exists', '<s:partial:nope/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-fluent-tag-method-exists', "{{ Statamic::tag('partial:nope') }}"))->toHaveCount(1)
        ->and(lintStatamic('statamic-tags-directive-tag-exists', "@tags('partial:nope')"))->toHaveCount(1);
});

it('validates the rewritten index method on replacement partial tags', function (): void {
    $tags = statamicRegistry('statamic.tags');

    $replacement = new class extends Tags
    {
        public function anotherMethod(): string
        {
            return '';
        }
    };
    $tags->put('partial', $replacement::class);

    expect(lintStatamic('statamic-tag-method-exists', '<s:partial:card/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-method-exists', '<s:partial:exists/>'))->toHaveCount(1);
});

it('matches the live registry case on every invocation surface', function (): void {
    $tags = statamicRegistry('statamic.tags');

    $tags->put('CaseFixture', FixtureTag::class);

    expect(lintStatamic('statamic-tag-exists', '<s:CaseFixture/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-exists', '<s:casefixture/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-fluent-tag-exists', "{{ Statamic::tag('CaseFixture') }}"))->toHaveCount(0)
        ->and(lintStatamic('statamic-fluent-tag-exists', "{{ Statamic::tag('casefixture') }}"))->toHaveCount(1)
        ->and(lintStatamic('statamic-tags-directive-tag-exists', "@tags('CaseFixture')"))->toHaveCount(0)
        ->and(lintStatamic('statamic-tags-directive-tag-exists', "@tags('casefixture')"))->toHaveCount(1);
});

it('accepts concrete and wildcard methods but rejects impossible methods', function (): void {
    expect(lintStatamic('statamic-tag-method-exists', '<s:fixture:known_method/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-method-exists', '<s:wildcard_fixture:anything/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-method-exists', '<s:fixture:nope/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-method-exists', '<s:fixture:needs_argument/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-method-exists', '<s:get_error/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-method-exists', '<s:get_error:email/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-method-exists', '<s:section>content</s:section>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-method-exists', '<s:section:sidebar>content</s:section:sidebar>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-method-exists', '<s:yield/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-method-exists', '<s:yield:sidebar/>'))->toHaveCount(0);
});

it('rejects inaccessible and incompatible wildcard handlers', function (): void {
    $tags = statamicRegistry('statamic.tags');

    $private = new class extends Tags
    {
        public function exercisePrivateWildcard(): string
        {
            return $this->wildcard('probe');
        }

        private function wildcard(string $method): string
        {
            return $method;
        }
    };
    $arity = new class extends Tags
    {
        public function wildcard(string $method, string $required): string
        {
            return $method.$required;
        }
    };
    $array = new class extends Tags
    {
        /** @param array<string, mixed> $method */
        public function wildcard(array $method): string
        {
            return implode(',', array_keys($method));
        }
    };
    $tags->put('private_wildcard', $private::class);
    $tags->put('arity_wildcard', $arity::class);
    $tags->put('array_wildcard', $array::class);

    expect(lintStatamic('statamic-tag-method-exists', '<s:private_wildcard:anything/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-fluent-tag-method-exists', "{{ Statamic::tag('private_wildcard:anything') }}"))->toHaveCount(1)
        ->and(lintStatamic('statamic-tags-directive-tag-exists', "@tags('private_wildcard:anything')"))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-method-exists', '<s:arity_wildcard:anything/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-method-exists', '<s:array_wildcard:anything/>'))->toHaveCount(1);
});

it('invalidates all invocation surfaces when an object binding changes wildcard dispatch', function (): void {
    $tags = statamicRegistry('statamic.tags');

    $tag = new class extends Tags
    {
        public function disableWildcard(): void
        {
            $this->wildcardMethod = 'doesNotExist';
        }

        public function wildcard(string $method): string
        {
            return $method;
        }
    };
    $tags->put('mutable_wildcard', $tag);

    expect(lintStatamic('statamic-tag-method-exists', '<s:mutable_wildcard:anything/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-fluent-tag-method-exists', "{{ Statamic::tag('mutable_wildcard:anything') }}"))->toHaveCount(0)
        ->and(lintStatamic('statamic-tags-directive-tag-exists', "@tags('mutable_wildcard:anything')"))->toHaveCount(0);

    $tag->disableWildcard();

    expect(lintStatamic('statamic-tag-method-exists', '<s:mutable_wildcard:anything/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-fluent-tag-method-exists', "{{ Statamic::tag('mutable_wildcard:anything') }}"))->toHaveCount(1)
        ->and(lintStatamic('statamic-tags-directive-tag-exists', "@tags('mutable_wildcard:anything')"))->toHaveCount(1);
});

it('requires pairs only for tags whose runtime rejects self-closing use', function (): void {
    expect(lintStatamic('statamic-tag-pair-required', '<s:scope:card/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-pair-required', '<s:scope:card>content</s:scope:card>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-pair-required', '<s:cache/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-pair-required', "{{ Statamic::tag('scope:card') }}"))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-pair-required', "{{ Statamic::tag('scope:card')->withContent('body') }}"))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-pair-required', "{{ Statamic::tag('scope:card')->withContent([]) }}"))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-pair-required', "{{ Statamic::tag('scope:card')->withContent('') }}"))->toHaveCount(1)
        ->and(lintStatamic('statamic-tag-pair-required', "{{ Statamic::tag('scope:card')->withContent(\$content) }}"))->toHaveCount(0)
        ->and(lintStatamic('statamic-tag-pair-required', "@tags('scope:card')"))->toHaveCount(1);
});

it('reports executable Statamic debug tags on every Blade invocation surface', function (string $source, int $count): void {
    $violations = lintStatamic('statamic-no-debug-tags', $source);

    expect($violations)->toHaveCount($count);
})->with([
    'short dump component' => ['<s:dump/>', 1],
    'ddd alias' => ['<s:ddd/>', 1],
    'dump wildcard' => ['<s:dump:user/>', 1],
    'impossible dd method is owned by method validation' => ['<s:dd:user/>', 0],
    'terminal fluent dump' => ["{{ Statamic::tag('dump:user') }}", 1],
    'unexecuted fluent builder' => ["@php(Statamic::tag('dump:user'))", 0],
    'tags directive dump' => ["@tags('dump:user')", 1],
    'embedded Antlers dump tag' => ['@antlers {{ dump }} @endantlers', 1],
    'embedded Antlers dump wildcard' => ['@antlers {{ dump:user }} @endantlers', 1],
    'embedded Antlers dd tag' => ['@antlers {{ dd }} @endantlers', 1],
    'embedded Antlers ddd modifier' => ['@antlers {{ title | ddd }} @endantlers', 1],
    'embedded Antlers dump modifier' => ['@antlers {{ title | dump }} @endantlers', 1],
    'Antlers debug name in a comment' => ['@antlers {{# dump #}} @endantlers', 0],
    'invalid Antlers is owned by syntax validation' => ['@antlers {{ if }} {{ dump }} @endantlers', 0],
    'ordinary tag' => ['<s:collection from="pages"/>', 0],
]);

it('uses exact UTF-8-safe ranges for embedded Antlers debug references', function (string $source, string $needle, bool $last): void {
    $violation = lintStatamic('statamic-no-debug-tags', $source)[0];
    $expectedStart = templateOffset($source, $needle, $last);

    expect($violation->start->offset)->toBe($expectedStart)
        ->and($violation->end->offset)->toBe($expectedStart + strlen($needle));
})->with([
    'tag' => ["\xC3\xA9 @antlers {{ dump }} @endantlers", '{{ dump }}', false],
    'modifier' => ["\xC3\xA9 @antlers {{ title | dump }} @endantlers", 'dump', false],
    'repeated modifier name after multibyte text' => ["@antlers {{ '\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9 dump' | dump }} @endantlers", 'dump', true],
]);

it('skips a debug handle replaced by a non-debug tag', function (): void {
    $tags = statamicRegistry('statamic.tags');

    $tags->put('dump', FixtureTag::class);

    expect(lintStatamic('statamic-no-debug-tags', '<s:dump/>'))->toHaveCount(0);
});

it('skips a core debug modifier replaced by the application', function (): void {
    $modifiers = statamicRegistry('statamic.modifiers');
    $source = '@antlers {{ title | dump }} @endantlers';

    expect(lintStatamic('statamic-no-debug-tags', $source))->toHaveCount(1);

    $modifiers->put('dump', FixtureTag::class.'@index');

    expect(lintStatamic('statamic-no-debug-tags', $source))->toHaveCount(0);
});

it('does not duplicate the core Blade debug rule for Statamic components', function (): void {
    $result = app(SheathManager::class)->lint(
        '<s:dump/>',
        'test.blade.php',
        Config::make(['rules' => [
            'blade-no-debug' => 'error',
            'statamic-no-debug-tags' => 'error',
        ]]),
    );

    expect($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->ruleId)->toBe('statamic-no-debug-tags');
});

it('requires known parameters that Statamic needs to run a tag', function (string $source, int $count): void {
    expect(lintStatamic('statamic-required-tag-parameter', $source))->toHaveCount($count);
})->with([
    'asset missing source' => ['<s:asset/>', 1],
    'asset source alias' => ['<s:asset src="logo.svg"/>', 0],
    'assets missing selector' => ['<s:assets/>', 1],
    'assets container' => ['<s:assets container="images"/>', 0],
    'assets skips an empty selector when collection is usable' => ['<s:assets container="" collection="articles"/>', 0],
    'assets path remains owned by the path-container rule after an empty selector' => ['<s:assets container="" path="photos"/>', 0],
    'assets path is owned by the path-container rule' => ['<s:assets path="photos"/>', 0],
    'collection missing source' => ['<s:collection></s:collection>', 1],
    'collection index missing source' => ['<s:collection:index/>', 1],
    'collection count missing source' => ['<s:collection:count/>', 1],
    'collection shorthand supplies source' => ['<s:collection:pages/>', 0],
    'collection parameter supplies source' => ['<s:collection from="pages"/>', 0],
    'children may inherit its collection from context' => ['<s:children></s:children>', 0],
    'self-closing children is removed by the Statamic compiler' => ['<s:children/>', 0],
    'dictionary missing handle' => ['<s:dictionary/>', 1],
    'dictionary shorthand supplies handle' => ['<s:dictionary:countries/>', 0],
    'can requires permission without shorthand' => ['<s:can></s:can>', 1],
    'can permission' => ['<s:can permission="edit entries"></s:can>', 0],
    'cookie value requires key' => ['<s:cookie:value/>', 1],
    'cookie shorthand supplies key' => ['<s:cookie:theme/>', 0],
    'foreach requires explicit array without shorthand' => ['<s:foreach></s:foreach>', 1],
    'foreach shorthand supplies context variable' => ['<s:foreach:items></s:foreach:items>', 0],
    'form create may inherit its handle from context' => ['<s:form:create/>', 0],
    'form set requires explicit handle' => ['<s:form:set/>', 1],
    'bare form requires explicit handle' => ['<s:form/>', 1],
    'form handle alias' => ['<s:form:create in="contact"/>', 0],
    'delete passkey form requires an id' => ['<s:user:delete_passkey_form/>', 1],
    'delete passkey form receives an id' => ['<s:user:delete_passkey_form id="passkey-id"/>', 0],
    'member alias delete passkey form requires an id' => ['<s:member:delete_passkey_form/>', 1],
    'get content missing source' => ['<s:get_content/>', 1],
    'get content source' => ['<s:get_content from="entry-id"/>', 0],
    'get files missing directory' => ['<s:get_files/>', 1],
    'get files directory alias' => ['<s:get_files from="images"/>', 0],
    'glide missing source' => ['<s:glide/>', 1],
    'glide batch transforms child images' => ['<s:glide:batch><img src="image.jpg"></s:glide:batch>', 0],
    'glide shorthand supplies context field' => ['<s:glide:hero/>', 0],
    'installed missing package' => ['<s:installed/>', 1],
    'installed shorthand supplies package' => ['<s:installed:statamic/cms/>', 0],
    'increment reset requires counter' => ['<s:increment:reset/>', 1],
    'group check requires group without shorthand' => ['<s:in></s:in>', 1],
    'role check requires role without shorthand' => ['<s:is></s:is>', 1],
    'mount url missing handle' => ['<s:mount_url/>', 1],
    'mount url shorthand supplies handle' => ['<s:mount_url:pages/>', 0],
    'mix missing source' => ['<s:mix/>', 1],
    'mix source' => ['<s:mix src="css/app.css"/>', 0],
    'oauth login url missing provider' => ['<s:oauth:login_url/>', 1],
    'oauth provider shorthand' => ['<s:oauth:github/>', 0],
    'partial requires explicit source' => ['<s:partial/>', 1],
    'partial src' => ['<s:partial src="card"/>', 0],
    'partial shorthand supplies source' => ['<s:partial:card/>', 0],
    'range missing end' => ['<s:range/>', 1],
    'loop times alias' => ['<s:loop times="3"/>', 0],
    'route missing name' => ['<s:route/>', 1],
    'route shorthand supplies name' => ['<s:route:home/>', 0],
    'redirect missing destination' => ['<s:redirect/>', 1],
    'redirect named route' => ['<s:redirect route="home"/>', 0],
    'redirect route works after an empty destination' => ['<s:redirect to="" route="home"/>', 0],
    'rotate missing values' => ['<s:rotate/>', 1],
    'rotate zero is unusable' => ['<s:rotate between="0"/>', 1],
    'switch values' => ['<s:switch between="odd|even"/>', 0],
    'session value requires key' => ['<s:session:value/>', 1],
    'session forget rejects an unusable zero key list' => ['<s:session:forget keys="0"/>', 1],
    'cookie forget rejects an unusable zero key list' => ['<s:cookie:forget keys="0"/>', 1],
    'structure missing target' => ['<s:structure></s:structure>', 1],
    'structure shorthand supplies target' => ['<s:structure:main></s:structure:main>', 0],
    'svg missing source' => ['<s:svg/>', 1],
    'svg shorthand supplies source' => ['<s:svg:logo/>', 0],
    'theme image requires source' => ['<s:theme:img/>', 1],
    'theme css has an app default' => ['<s:theme:css/>', 0],
    'translation requires a key without shorthand' => ['<s:trans/>', 1],
    'translation key parameter' => ['<s:trans key="messages.title"/>', 0],
    'vite missing src' => ['<s:vite:asset/>', 1],
    'vite empty src' => ['<s:vite:asset src=""/>', 1],
    'vite normalized false src' => ['<s:vite:asset src="false"/>', 1],
    'vite uppercase false remains a literal src' => ['<s:vite:asset src="FALSE"/>', 0],
    'vite bound false src' => ['<s:vite:asset :src="false"/>', 1],
    'vite bound false string src' => ["<s:vite:asset :src=\"'false'\"/>", 1],
    'vite bound literal src' => ["<s:vite:asset :src=\"'resources/js/app.js'\"/>", 0],
    'vite boolean src passes required guard' => ['<s:vite:asset src/>', 0],
    'vite asset zero src' => ['<s:vite:asset src="0"/>', 1],
    'vite content zero src' => ['<s:vite:content src="0"/>', 1],
    'vite src' => ['<s:vite:asset src="resources/js/app.js"/>', 0],
    'vite index missing src' => ['<s:vite:index/>', 1],
    'vite index zero src' => ['<s:vite:index src="0"/>', 1],
    'get site missing handle' => ['<s:get_site/>', 1],
    'query missing builder' => ['<s:query/>', 1],
    'query accepts zero builder' => ['<s:query builder="0"/>', 0],
    'taxonomy count missing from' => ['<s:taxonomy:count/>', 1],
    'taxonomy index missing from' => ['<s:taxonomy:index/>', 1],
    'taxonomy handle is not a v6 alias' => ['<s:taxonomy handle="tags"/>', 1],
    'first empty taxonomy alias wins over later usable alias' => ['<s:taxonomy from="" in="tags"/>', 1],
    'later taxonomy alias works when earlier alias is absent' => ['<s:taxonomy in="tags"/>', 0],
    'dynamic earlier taxonomy alias stands down' => ['<s:taxonomy :from="$taxonomy" in="tags"/>', 0],
    'dynamic attributes are skipped' => ['<s:partial {{ $attributes }}/>', 0],
    'exhaustive conditional source paths pass' => ['<s:collection @if($news) from="news" @else from="pages" @endif/>', 0],
    'optional conditional source reports missing path' => ['<s:collection @if($news) from="news" @endif/>', 1],
    'conditional dynamic source stands down only on that path' => ['<s:collection @if($news) :from="$collection" @else from="pages" @endif/>', 0],
    'correlated complex source providers do not invent a missing path' => ['<s:collection @if($news && $ready) from="news" @endif @if(!($news && $ready)) from="pages" @endif/>', 0],
    'fixed class directive cannot supply src' => ["<s:vite @class(['module'])/>", 1],
    'fixed checked directive cannot supply src' => ['<s:vite @checked(true)/>', 1],
]);

it('enforces special tag placement', function (string $source, int $count): void {
    expect(lintStatamic('statamic-special-child-placement', $source))->toHaveCount($count);
})->with([
    'top-level slot' => ['<s:slot:name>Hi</s:slot:name>', 1],
    'top-level dot slot' => ['<s:slot.name>Hi</s:slot.name>', 1],
    'partial slot' => ['<s:partial src="card"><s:slot:name>Hi</s:slot:name></s:partial>', 0],
    'partial dot slot' => ['<s:partial src="card"><s:slot.name>Hi</s:slot.name></s:partial>', 0],
    'top-level no results' => ['<s:no_results>None</s:no_results>', 1],
    'query no results' => ['<s:collection:articles><s:no_results>None</s:no_results></s:collection:articles>', 0],
]);

it('lets placement own special-tag failures without an unknown-tag duplicate', function (): void {
    $result = app(SheathManager::class)->lint(
        '<s:slot.name>Hi</s:slot.name>',
        'test.blade.php',
        Config::make(['rules' => [
            'statamic-tag-exists' => 'error',
            'statamic-special-child-placement' => 'error',
        ]]),
    );

    expect($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->ruleId)->toBe('statamic-special-child-placement');
});

it('uses the final duplicate component parameter like Statamic compiled PHP', function (): void {
    expect(lintStatamic('statamic-required-tag-parameter', '<s:vite src="" src="app.js"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-required-tag-parameter', '<s:vite src="app.js" src=""/>'))->toHaveCount(1);
});

it('requires arguments only for directives that cannot run without one', function (string $source, int $count): void {
    expect(lintStatamic('statamic-directive-arguments', $source))->toHaveCount($count);
})->with([
    'tags' => ['@tags', 1],
    'tags empty parentheses' => ['@tags()', 1],
    'frontmatter' => ['@frontmatter', 1],
    'nocache' => ['@nocache', 1],
    'cascade remains valid without args' => ['@cascade', 0],
    'tags with args' => ["@tags('collection:articles')", 0],
]);

it('rejects statically malformed @tags definitions before Statamic reads undefined variables', function (string $source, int $count): void {
    expect(lintStatamic('statamic-directive-arguments', $source))->toHaveCount($count);
})->with([
    'empty nested list' => ['@tags([[]])', 1],
    'empty aliased map' => ["@tags(['one' => []])", 1],
    'integer definition' => ['@tags(1)', 1],
    'null is an empty directive set' => ['@tags(null)', 0],
    'null array item is malformed' => ['@tags([null])', 1],
    'literal tag parameters must be an array' => ["@tags(['one' => ['fixture' => 'invalid']])", 1],
    'dynamic tag parameters stand down' => ["@tags(['one' => ['fixture' => \$params]])", 0],
    'empty outer array is valid' => ['@tags([])', 0],
    'dynamic definitions stand down' => ['@tags($tags)', 0],
]);

it('matches Statamic query parameter conflicts while preserving boolean pagination', function (string $source, int $count): void {
    expect(lintStatamic('statamic-incompatible-query-parameters', $source))->toHaveCount($count);
})->with([
    'integer paginate and limit' => ['<s:collection:articles paginate="10" limit="20"/>', 1],
    'leading integer junk and limit' => ['<s:collection:articles paginate="1foo" limit="20"/>', 1],
    'paginate and chunk' => ['<s:collection:articles paginate="10" chunk="2"/>', 1],
    'boolean paginate and limit' => ['<s:collection:articles paginate limit="20"/>', 0],
    'integer paginate and boolean limit' => ['<s:collection:articles paginate="10" limit/>', 1],
    'dynamic values are skipped' => ['<s:collection:articles :paginate="$perPage" limit="20"/>', 0],
    'collection count bypasses result guard' => ['<s:collection:count from="articles" paginate="10" limit="20"/>', 0],
    'taxonomy count bypasses result guard' => ['<s:taxonomy:count from="tags" paginate="10" limit="20"/>', 0],
    'get content does not use guarded results' => ['<s:get_content from="id" paginate="10" limit="20"/>', 0],
    'fluent numeric paginate and limit' => ["{{ Statamic::tag('collection:articles')->paginate('10')->limit('20') }}", 1],
    'fluent paginate and chunk' => ["{{ Statamic::tag('collection:articles')->paginate('10')->chunk('2') }}", 1],
    'fluent boolean paginate and limit' => ["{{ Statamic::tag('collection:articles')->paginate()->limit('20') }}", 0],
    'tags directive numeric paginate and limit' => ["@tags(['articles' => ['collection:articles' => ['paginate' => 10, 'limit' => 20]]])", 1],
    'tags directive paginate and chunk' => ["@tags(['articles' => ['collection:articles' => ['paginate' => 10, 'chunk' => 2]]])", 1],
    'mutually exclusive paginate and chunk' => ['<s:collection:articles @if($paged) paginate="10" @else chunk="2" @endif/>', 0],
    'one branch contains both incompatible parameters' => ['<s:collection:articles @if($paged) paginate="10" chunk="2" @else limit="2" @endif/>', 1],
    'correlated complex query providers do not invent a conflicting path' => ['<s:collection:articles @if($paged && $ready) paginate="10" @endif @if(!($paged && $ready)) chunk="2" @endif/>', 0],
]);

it('limits query conflicts to methods that execute the result guard', function (): void {
    $tags = statamicRegistry('statamic.tags');

    $unrelatedTraitUser = new class extends Tags
    {
        use GetsQueryResults;

        public function index(): string
        {
            return '';
        }
    };
    $tags->put('unrelated_trait_user', $unrelatedTraitUser::class);

    expect(lintStatamic('statamic-incompatible-query-parameters', '<s:form:create paginate="10" chunk="2"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-incompatible-query-parameters', '<s:form:submissions paginate="10" chunk="2"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-incompatible-query-parameters', '<s:users paginate="10" chunk="2"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-incompatible-query-parameters', '<s:unrelated_trait_user paginate="10" chunk="2"/>'))->toHaveCount(0);
});

it('requires a container when querying assets by path', function (): void {
    expect(lintStatamic('statamic-assets-path-requires-container', '<s:assets path="photos"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets path=""/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets path="photos" container=""/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets path/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets path="photos" container/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets path="photos" collection/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets container="media" path="photos"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets:index path="photos"/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets:gallery path="photos"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets :path="$path"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets path="photos" :container="$container"/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets @if($usePath) path="photos" @else container="media" @endif/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets @if($usePath) path="photos" container="media" @else path="" @endif/>'))->toHaveCount(0)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets path="photos" @if($hasContainer) container="media" @endif/>'))->toHaveCount(1)
        ->and(lintStatamic('statamic-assets-path-requires-container', '<s:assets @if($usePath && $ready) path="photos" @endif @if($usePath && $ready) container="media" @endif/>'))->toHaveCount(0);
});

it('requires an assets container on fluent and @tags invocation surfaces', function (): void {
    expect(lintStatamic('statamic-assets-path-requires-container', "{{ Statamic::tag('assets')->path('photos') }}"))->toHaveCount(1)
        ->and(lintStatamic('statamic-assets-path-requires-container', "{{ Statamic::tag('assets')->path('photos')->container('media') }}"))->toHaveCount(0)
        ->and(lintStatamic('statamic-assets-path-requires-container', "@tags(['images' => ['assets' => ['path' => 'photos']]])"))->toHaveCount(1)
        ->and(lintStatamic('statamic-assets-path-requires-container', "@tags(['images' => ['assets' => ['path' => 'photos', 'container' => 'media']]])"))->toHaveCount(0);
});

it('ends component diagnostics at the opening tag instead of including descendants', function (): void {
    $source = '<s:definitely_missing><div>child</div></s:definitely_missing>';
    /** @var Violation $violation */
    $violation = lintStatamic('statamic-tag-exists', $source)[0];

    $openingEnd = templateOffset($source, '>');
    expect($violation->start->offset)->toBe(0)
        ->and($violation->end->offset)->toBe($openingEnd + 1);
});
