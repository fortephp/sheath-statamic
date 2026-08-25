<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\SheathManager;

it('keeps core Blade and HTML rules out of embedded Antlers while retaining package diagnostics', function (): void {
    $source = '@antlers <img> {{ if }} @endantlers';
    $result = app(SheathManager::class)->lint(
        $source,
        'test.blade.php',
        Config::make(['rules' => [
            'a11y-alt-text' => 'error',
            'statamic-antlers-region-syntax' => 'error',
        ]]),
    );

    expect($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->ruleId)->toBe('statamic-antlers-region-syntax');
});

it('preserves byte offsets and line endings when masking embedded Antlers', function (): void {
    $source = "before\r\n@antlers\r\n{{ title }}\r\n@endantlers\r\nafter";
    $regions = app(SheathManager::class)->getIgnoredRegionRegistry()->regions($source, 'test.blade.php');
    $expectedStart = templateOffset($source, '@antlers');
    $close = templateOffset($source, '@endantlers');

    expect($regions)->toHaveCount(1)
        ->and($regions[0]->startOffset)->toBe($expectedStart)
        ->and($regions[0]->endOffset)->toBe($close + strlen('@endantlers'));
});

it('matches Statamic first-open-to-first-close regions for nested markers', function (): void {
    $source = '@antlers a @antlers b @endantlers @endantlers';
    $regions = app(SheathManager::class)->getIgnoredRegionRegistry()->regions($source, 'test.blade.php');
    $firstClose = templateOffset($source, '@endantlers');

    expect($regions)->toHaveCount(1)
        ->and($regions[0]->startOffset)->toBe(0)
        ->and($regions[0]->endOffset)->toBe($firstClose + strlen('@endantlers'))
        ->and($regions[0]->endOffset)->toBeLessThan(strlen($source));
});

it('does not protect unmatched markers that the Statamic precompiler leaves in Blade', function (): void {
    $registry = app(SheathManager::class)->getIgnoredRegionRegistry();

    expect($registry->regions('@antlers {{ title }}', 'test.blade.php'))->toHaveCount(0)
        ->and($registry->regions('{{ title }} @endantlers', 'test.blade.php'))->toHaveCount(0);
});
