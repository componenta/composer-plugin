<?php

declare(strict_types=1);

use Componenta\ComposerPlugin\ProviderCollector;
use Componenta\ComposerPlugin\ProviderExtractor;
use Componenta\ComposerPlugin\ProviderFileRenderer;
use Componenta\ComposerPlugin\ProviderPackage;

it('rejects invalid provider names at both metadata and rendering boundaries', function (string $name): void {
    $package = new ProviderPackage('example/package', [
        'componenta' => ['config-providers' => [$name]],
    ]);

    expect(fn() => (new ProviderExtractor())->extract($package))
        ->toThrow(UnexpectedValueException::class, 'example/package');
    expect(fn() => (new ProviderFileRenderer())->render([$name]))
        ->toThrow(UnexpectedValueException::class);
})->with([
    'self', 'parent', 'static', 'namespace', 'int', 'bad name',
    'Example\\readonly', 'Example\\return', 'Example\\__CLASS__',
    "Example\\Provider\n", 'Example\\\\Provider', "Example\\Provider\0",
    'Example;echo 1;', '',
]);

it('rejects explicit null and non-list metadata', function (array $extra): void {
    expect(fn() => (new ProviderExtractor())->extract(new ProviderPackage('example/package', $extra)))
        ->toThrow(UnexpectedValueException::class);
})->with([
    [['componenta' => null]],
    [['componenta' => false]],
    [['componenta' => ['config-providers' => null]]],
    [['componenta' => ['config-providers' => ['key' => 'Example\\Provider']]]],
    [['componenta' => ['config-providers' => [42]]]],
]);

it('preserves the first spelling when provider names differ only by ASCII case', function (): void {
    $packages = [
        new ProviderPackage('example/one', ['componenta' => ['config-providers' => ['Example\\Provider']]]),
        new ProviderPackage('example/two', ['componenta' => ['config-providers' => ['example\\provider']]]),
    ];

    expect((new ProviderCollector())->collect($packages))->toBe(['Example\\Provider']);
});

it('renders a context-independent list of strings without autoloading providers', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'componenta-render-');
    $providers = ['Example\\NotLoaded', 'Example\\Провайдер', 'Example\\Enum', 'Example\\From'];

    try {
        file_put_contents($file, (new ProviderFileRenderer())->render($providers));
        expect(require $file)->toBe($providers)
            ->and(class_exists($providers[0], false))->toBeFalse();
    } finally {
        unlink($file);
    }
});

it('rejects non-list renderer input', function (): void {
    expect(fn() => (new ProviderFileRenderer())->render(['key' => 'Example\\Provider']))
        ->toThrow(UnexpectedValueException::class);
});
