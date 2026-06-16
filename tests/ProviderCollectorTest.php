<?php

declare(strict_types=1);

use Componenta\ComposerPlugin\ProviderCollector;
use Componenta\ComposerPlugin\ProviderFileRenderer;
use Componenta\ComposerPlugin\ProviderFileWriter;
use Componenta\ComposerPlugin\ProviderPackage;

it('collects componenta config providers from package extra metadata', function (): void {
    $providers = (new ProviderCollector())->collect([
        new ProviderPackage('componenta/one', [
            'componenta' => [
                'config-providers' => [
                    'Componenta\\One\\ConfigProvider',
                    'Componenta\\Shared\\ConfigProvider',
                ],
            ],
        ]),
        new ProviderPackage('vendor/ignored', []),
        new ProviderPackage('componenta/two', [
            'componenta' => [
                'config-providers' => [
                    'Componenta\\Shared\\ConfigProvider',
                    'Componenta\\Two\\ConfigProvider',
                ],
            ],
        ]),
    ]);

    expect($providers)->toBe([
        'Componenta\\One\\ConfigProvider',
        'Componenta\\Shared\\ConfigProvider',
        'Componenta\\Two\\ConfigProvider',
    ]);
});

it('rejects invalid provider metadata', function (): void {
    expect(fn() => (new ProviderCollector())->collect([
        new ProviderPackage('componenta/broken', [
            'componenta' => [
                'config-providers' => ['not a class'],
            ],
        ]),
    ]))->toThrow(UnexpectedValueException::class, 'componenta/broken');
});

it('renders provider file content', function (): void {
    $contents = (new ProviderFileRenderer())->render([
        'Componenta\\One\\ConfigProvider',
        'Componenta\\Two\\ConfigProvider',
    ]);

    expect($contents)->toBe(<<<'PHP'
<?php

declare(strict_types=1);

return [
    Componenta\One\ConfigProvider::class,
    Componenta\Two\ConfigProvider::class,
];

PHP);
});

it('does not rewrite unchanged provider file', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-provider-writer-' . bin2hex(random_bytes(4));
    mkdir($directory, 0775, true);
    $file = $directory . '/componenta-providers.php';
    $contents = (new ProviderFileRenderer())->render([
        'Componenta\\One\\ConfigProvider',
    ]);

    file_put_contents($file, $contents);
    touch($file, time() - 100);
    clearstatcache(true, $file);
    $mtime = filemtime($file);

    try {
        $written = (new ProviderFileWriter())->write($file, [
            'Componenta\\One\\ConfigProvider',
        ]);

        clearstatcache(true, $file);
        expect($written)->toBeFalse()
            ->and(file_get_contents($file))->toBe($contents)
            ->and(filemtime($file))->toBe($mtime);
    } finally {
        @unlink($file);
        @rmdir($directory);
    }
});

it('writes changed provider file content', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-provider-writer-' . bin2hex(random_bytes(4));
    mkdir($directory, 0775, true);
    $file = $directory . '/componenta-providers.php';
    file_put_contents($file, 'old');

    try {
        $written = (new ProviderFileWriter())->write($file, [
            'Componenta\\Two\\ConfigProvider',
        ]);

        expect($written)->toBeTrue()
            ->and(file_get_contents($file))->toBe((new ProviderFileRenderer())->render([
                'Componenta\\Two\\ConfigProvider',
            ]));
    } finally {
        foreach (glob($directory . '/componenta-providers.php.*') ?: [] as $tmp) {
            @unlink($tmp);
        }

        @unlink($file);
        @rmdir($directory);
    }
});
