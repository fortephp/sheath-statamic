<?php

declare(strict_types=1);

use Forte\Sheath\SheathManager;
use Forte\Sheath\Statamic\ServiceProvider;
use Illuminate\Support\Facades\Artisan;

it('registers every preset rule', function (): void {
    $manager = app(SheathManager::class);
    $registeredIds = array_map(
        static fn (string $rule): string => app($rule)->getId(),
        ServiceProvider::RULES,
    );
    $missingIds = array_values(array_filter(
        $registeredIds,
        static fn (string $id): bool => ! $manager->hasRule($id),
    ));
    $presetIds = array_keys(ServiceProvider::PRESET);
    sort($registeredIds);
    sort($presetIds);

    $declared = $manager->getPackagePresets()->declared('statamic');
    if (! is_array($declared)) {
        throw new RuntimeException('The Statamic preset must be registered.');
    }
    $expectedPreset = ServiceProvider::PRESET;
    ksort($declared);
    ksort($expectedPreset);

    expect($missingIds)->toBe([])
        ->and($declared)->toBe($expectedPreset)
        ->and($registeredIds)->toBe($presetIds);
});

it('runs the Statamic preset through the lint command', function (): void {
    $exitCode = Artisan::call('sheath:lint', [
        'paths' => [dirname(__DIR__).'/Fixtures/project-views/exact.blade.php'],
        '--preset' => ['statamic'],
        '--format' => 'json',
    ]);

    expect($exitCode)->toBe(0);
});
