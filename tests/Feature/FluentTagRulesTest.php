<?php

declare(strict_types=1);

it('checks only literal fluent calls that are executed', function (string $source, int $count): void {
    expect(lintStatamic('statamic-fluent-tag-exists', $source))->toHaveCount($count);
})->with([
    'facade echo' => ["{{ Statamic::tag('definitely_missing') }}", 1],
    'fully qualified facade fetch' => ["@php(\\Statamic\\Statamic::tag('definitely_missing')->fetch())", 1],
    'qualified helper iteration' => ["@foreach(\\Statamic\\View\\Blade\\tag('definitely_missing') as \$item){{ \$item }}@endforeach", 1],
    'native PHP iteration' => ["@php foreach (Statamic::tag('definitely_missing') as \$item) {} @endphp", 1],
    'yield-from iteration' => ["@php yield from Statamic::tag('definitely_missing'); @endphp", 1],
    'iterator conversion' => ["@php iterator_to_array(Statamic::tag('definitely_missing'), false); @endphp", 1],
    'iterator count' => ["@php iterator_count(Statamic::tag('definitely_missing')); @endphp", 1],
    'native PHP echo' => ["@php echo Statamic::tag('definitely_missing'); @endphp", 1],
    'native PHP print' => ["@php print(Statamic::tag('definitely_missing')); @endphp", 1],
    'known tag' => ["{{ Statamic::tag('fixture') }}", 0],
    'dynamic name' => ['{{ Statamic::tag($name) }}', 0],
    'build only assignment' => ["@php \$tag = Statamic::tag('definitely_missing'); @endphp", 0],
    'build only directive argument' => ["@if(Statamic::tag('definitely_missing')) x @endif", 0],
    'literal-looking HTML text' => ["<code>Statamic::tag('definitely_missing')->fetch()</code>", 0],
    'literal-looking PHP string' => ["@php \$example = \"Statamic::tag('definitely_missing')->fetch()\"; @endphp", 0],
    'component bound attribute' => ["<s:partial src=\"root-card\" :probe=\"Statamic::tag('definitely_missing')->fetch()\"/>", 1],
    'nested param call' => ["{{ Statamic::tag('fixture')->param('probe', Statamic::tag('definitely_missing')->fetch()) }}", 1],
    'explicit string cast' => ["{{ (string) Statamic::tag('definitely_missing') }}", 1],
    'left concatenation' => ["{{ 'prefix'.Statamic::tag('definitely_missing') }}", 1],
    'right concatenation' => ["{{ Statamic::tag('definitely_missing').'suffix' }}", 1],
    'bound attribute concatenation' => ["<div :data-x=\"'x'.Statamic::tag('definitely_missing')\"></div>", 1],
    'strval conversion' => ["{{ strval(Statamic::tag('definitely_missing')) }}", 1],
    'escaped global strval conversion' => ["@php(\\strval(Statamic::tag('definitely_missing')))", 1],
    'html escape conversion' => ["@php(e(Statamic::tag('definitely_missing')))", 1],
    'array offset read' => ["@php \$value = Statamic::tag('definitely_missing')['value']; @endphp", 1],
    'array offset isset' => ["@php \$exists = isset(Statamic::tag('definitely_missing')['value']); @endphp", 1],
    'array offset write does not fetch' => ["@php Statamic::tag('definitely_missing')['value'] = 'x'; @endphp", 0],
    'property write does not fetch' => ["@php Statamic::tag('definitely_missing')->value = 'x'; @endphp", 0],
    'property isset does not fetch' => ["@php \$exists = isset(Statamic::tag('definitely_missing')->value); @endphp", 0],
    'property coalesce does not fetch' => ["@php \$value = Statamic::tag('definitely_missing')->value ?? 'fallback'; @endphp", 0],
    'object passed to function is not necessarily fetched' => ["{{ collect([Statamic::tag('definitely_missing')]) }}", 0],
    'same named object method is not a conversion' => ['{{ $object->strval(Statamic::tag(\'definitely_missing\')) }}', 0],
]);

it('validates concrete and wildcard fluent methods without duplicating missing handles', function (string $source, int $count): void {
    expect(lintStatamic('statamic-fluent-tag-method-exists', $source))->toHaveCount($count);
})->with([
    'known index' => ["{{ Statamic::tag('fixture') }}", 0],
    'known concrete method' => ["{{ Statamic::tag('fixture:known_method') }}", 0],
    'wildcard method' => ["{{ Statamic::tag('wildcard_fixture:anything') }}", 0],
    'missing concrete method' => ["{{ Statamic::tag('fixture:nope') }}", 1],
    'method needing an argument' => ["{{ Statamic::tag('fixture:needs_argument') }}", 1],
    'missing handle belongs to exists rule' => ["{{ Statamic::tag('definitely_missing:nope') }}", 0],
    'non-terminal missing method' => ["@php \$tag = Statamic::tag('fixture:nope'); @endphp", 0],
    'required method missing' => ["{{ Statamic::tag('get_error') }}", 1],
    'required method present' => ["{{ Statamic::tag('get_error:email') }}", 0],
]);

it('models fluent required parameter writes and terminal execution', function (string $source, int $count): void {
    expect(lintStatamic('statamic-fluent-required-parameters', $source))->toHaveCount($count);
})->with([
    'vite echo missing' => ["{{ Statamic::tag('vite:asset') }}", 1],
    'vite explicit fetch missing' => ["@php(Statamic::tag('vite:asset')->fetch())", 1],
    'fetch terminal ignores later result operations' => ["@php(Statamic::tag('vite:asset')->fetch()->afterFetch())", 1],
    'vite build only' => ["@php \$tag = Statamic::tag('vite:asset'); @endphp", 0],
    'dynamic tag name' => ['{{ Statamic::tag($tag) }}', 0],
    'dynamic source value' => ["{{ Statamic::tag('vite:asset')->src(\$src) }}", 0],
    'dynamic method name' => ["{{ Statamic::tag('vite:asset')->{\$method}('x') }}", 0],
    'dynamic params map' => ["{{ Statamic::tag('vite:asset')->params(\$params) }}", 0],
    'empty dynamic method parameter' => ["{{ Statamic::tag('vite:asset')->src('') }}", 1],
    'zero source' => ["{{ Statamic::tag('vite:asset')->src('0') }}", 1],
    'normalized false source' => ["{{ Statamic::tag('vite:asset')->src('false') }}", 1],
    'uppercase false source remains literal' => ["{{ Statamic::tag('vite:asset')->src('FALSE') }}", 0],
    'bare source becomes true' => ["{{ Statamic::tag('vite:asset')->src() }}", 0],
    'param default becomes true' => ["{{ Statamic::tag('vite:asset')->param('src') }}", 0],
    'param empty is missing' => ["{{ Statamic::tag('vite:asset')->param('src', '') }}", 1],
    'params static source' => ["{{ Statamic::tag('vite:asset')->params(['src' => 'app.js']) }}", 0],
    'params static empty source' => ["{{ Statamic::tag('vite:asset')->params(['src' => '']) }}", 1],
    'last concrete write wins valid' => ["{{ Statamic::tag('vite:asset')->src('')->src('app.js') }}", 0],
    'last concrete write wins missing' => ["{{ Statamic::tag('vite:asset')->src('app.js')->src('') }}", 1],
    'later opaque map stands down' => ["{{ Statamic::tag('vite:asset')->src('')->params(\$params) }}", 0],
    'later concrete write resolves opaque map' => ["{{ Statamic::tag('vite:asset')->params(\$params)->src('') }}", 1],
    'later dynamic param name stands down' => ["{{ Statamic::tag('vite:asset')->src('')->param(\$name, 'x') }}", 0],
    'taxonomy later alias' => ["{{ Statamic::tag('taxonomy')->in('tags') }}", 0],
    'taxonomy first present alias blocks later alias' => ["{{ Statamic::tag('taxonomy')->from('')->in('tags') }}", 1],
    'taxonomy dynamic first alias stands down' => ["{{ Statamic::tag('taxonomy')->from(\$from)->in('tags') }}", 0],
    'taxonomy wildcard supplies its own source' => ["{{ Statamic::tag('taxonomy:tags') }}", 0],
    'collection requires explicit source' => ["{{ Statamic::tag('collection') }}", 1],
    'collection source' => ["{{ Statamic::tag('collection')->from('pages') }}", 0],
    'assets any usable selector wins' => ["{{ Statamic::tag('assets')->container('')->collection('articles') }}", 0],
    'redirect route wins over empty destination' => ["{{ Statamic::tag('redirect')->to('')->route('home') }}", 0],
    'hexadecimal source is truthy' => ["{{ Statamic::tag('vite:asset')->src(0x1) }}", 0],
    'signed zero source is missing' => ["{{ Statamic::tag('vite:asset')->src(-0) }}", 1],
]);

it('understands the literal definition shapes accepted by @tags', function (string $source, int $count): void {
    expect(lintStatamic('statamic-tags-directive-tag-exists', $source))->toHaveCount($count);
})->with([
    'literal known' => ["@tags('fixture')", 0],
    'literal missing' => ["@tags('definitely_missing')", 1],
    'static list' => ["@tags(['fixture', 'definitely_missing'])", 1],
    'aliased strings' => ["@tags(['one' => 'fixture', 'two' => 'definitely_missing'])", 1],
    'nested definition' => ["@tags(['one' => ['fixture:known_method' => []]])", 0],
    'nested missing method' => ["@tags(['one' => ['fixture:nope' => []]])", 1],
    'nested wildcard method' => ["@tags(['one' => ['wildcard_fixture:anything' => []]])", 0],
    'direct map dispatches first parameter key as tag' => ["@tags(['fixture' => ['definitely_missing' => []]])", 1],
    'nested definition uses first key only' => ["@tags(['one' => ['fixture' => [], 'definitely_missing' => []]])", 0],
    'duplicate outer alias uses final value' => ["@tags(['one' => 'definitely_missing', 'one' => 'fixture'])", 0],
    'dynamic scalar' => ['@tags($tags)', 0],
    'dynamic outer value stands down' => ["@tags(['one' => \$tag])", 0],
    'dynamic outer key stands down' => ["@tags([\$name => 'definitely_missing'])", 0],
    'unpacked map stands down' => ["@tags([...\$tags, 'definitely_missing'])", 0],
    'escaped dollar remains a static literal' => ['@tags("definitely_\\$missing")', 1],
]);

it('checks required parameters in literal @tags definitions', function (string $source, int $count): void {
    expect(lintStatamic('statamic-tags-directive-required-parameters', $source))->toHaveCount($count);
})->with([
    'literal collection is missing source' => ["@tags('collection')", 1],
    'nested collection is missing source' => ["@tags(['items' => ['collection' => []]])", 1],
    'nested collection has source' => ["@tags(['items' => ['collection' => ['from' => 'pages']]])", 0],
    'nested collection empty source' => ["@tags(['items' => ['collection' => ['from' => '']]])", 1],
    'nested collection dynamic source' => ["@tags(['items' => ['collection' => ['from' => \$collection]]])", 0],
    'nested collection dynamic map' => ["@tags(['items' => ['collection' => \$params]])", 0],
    'normalized false source' => ["@tags(['asset' => ['vite:asset' => ['src' => 'false']]])", 1],
    'assets any usable selector wins' => ["@tags(['assets' => ['assets' => ['container' => '', 'collection' => 'articles']]])", 0],
    'redirect route wins over empty destination' => ["@tags(['redirect' => ['redirect' => ['to' => '', 'route' => 'home']]])", 0],
    'later concrete value overrides unpack' => ["@tags(['items' => ['collection' => [...\$params, 'from' => 'pages']]])", 0],
    'later unpack makes result unknown' => ["@tags(['items' => ['collection' => ['from' => '', ...\$params]]])", 0],
    'first alias wins for taxonomy' => ["@tags(['terms' => ['taxonomy' => ['from' => '', 'in' => 'tags']]])", 1],
    'partial suffix supplies source' => ["@tags(['card' => ['partial:card' => []]])", 0],
    'delete passkey form requires id' => ["@tags(['delete' => ['user:delete_passkey_form' => []]])", 1],
    'delete passkey form receives id' => ["@tags(['delete' => ['user:delete_passkey_form' => ['id' => 'passkey-id']]])", 0],
    'unknown tag belongs to identity rule' => ["@tags(['x' => ['definitely_missing' => []]])", 0],
]);

it('uses exact literal ranges for fluent and tags-directive identity diagnostics', function (): void {
    $source = "\xC3\xA9 {{ Statamic::tag('definitely_missing')->fetch() }}<section>child</section>";
    $violation = lintStatamic('statamic-fluent-tag-exists', $source)[0];
    $expectedStart = templateOffset($source, "'definitely_missing'");

    expect($violation->start->offset)->toBe($expectedStart)
        ->and($violation->end->offset)->toBe($expectedStart + strlen("'definitely_missing'"))
        ->and($violation->start->line)->toBe(1)
        ->and($violation->start->character)->toBe(20);

    $directive = "\xC3\xA9 @tags(['one' => 'definitely_missing'])<section>child</section>";
    $directiveViolation = lintStatamic('statamic-tags-directive-tag-exists', $directive)[0];
    $directiveStart = templateOffset($directive, "'definitely_missing'");
    expect($directiveViolation->start->offset)->toBe($directiveStart)
        ->and($directiveViolation->end->offset)->toBe($directiveStart + strlen("'definitely_missing'"));
});

it('bounds required-parameter diagnostics to the fluent call expression', function (): void {
    $source = "{{ Statamic::tag('vite:asset')->fetch() }}<section>child</section>";
    $violation = lintStatamic('statamic-fluent-required-parameters', $source)[0];
    $expectedStart = templateOffset($source, 'Statamic::tag');
    $expectedEnd = templateOffset($source, ' }}');

    expect($violation->start->offset)->toBe($expectedStart)
        ->and($violation->end->offset)->toBe($expectedEnd);
});

it('uses the literal tag token for TagsDirective required-parameter diagnostics', function (): void {
    $source = "\xC3\xA9 @tags(['articles' => ['collection' => []]])<section>child</section>";
    $violation = lintStatamic('statamic-tags-directive-required-parameters', $source)[0];
    $expectedStart = templateOffset($source, "'collection'");

    expect($violation->start->offset)->toBe($expectedStart)
        ->and($violation->end->offset)->toBe($expectedStart + strlen("'collection'"))
        ->and($violation->start->line)->toBe(1)
        ->and($violation->start->character)->toBe(25);
});
