<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function lifecycleJson(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function lifecycleRemoveTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

function lifecycleCommand(string $composer, string $directory, string $home, array $arguments): void
{
    $process = new Process(
        [PHP_BINARY, $composer, ...$arguments, '--no-interaction'],
        $directory,
        [
            'COMPOSER' => false,
            'COMPOSER_HOME' => $home,
            'COMPOSER_ROOT_VERSION' => false,
            'COMPOSER_VENDOR_DIR' => false,
            'COMPOSER_BIN_DIR' => false,
            'COMPOSER_CACHE_DIR' => $home . '/cache',
            'COMPOSER_NO_DEV' => false,
            'COMPOSER_SKIP_SCRIPTS' => false,
            'COMPOSER_NO_AUDIT' => '1',
            'COMPOSER_IGNORE_PLATFORM_REQ' => false,
            'COMPOSER_IGNORE_PLATFORM_REQS' => false,
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER_NO_INTERACTION' => '1',
        ],
        null,
        120,
    );
    $process->mustRun();
}

it('generates manifests across real Composer installation, upgrade from v1.0.3, dump-autoload and no-dev installation', function (): void {
    $repository = dirname(__DIR__, 2);
    $composer = $repository . '/tests/Fixtures/composer-cli.php';
    $directory = sys_get_temp_dir() . '/componenta-lifecycle-' . bin2hex(random_bytes(8));
    $project = $directory . '/project';
    $home = $directory . '/home';
    $packages = $directory . '/packages';
    $plugin = $packages . '/plugin';
    mkdir($project, 0775, true);
    mkdir($home, 0775, true);
    mkdir($plugin . '/src', 0775, true);

    try {
        $legacy = $repository . '/tests/Fixtures/composer-plugin-1.0.3';
        foreach (glob($legacy . '/src/*.php.txt') ?: [] as $file) {
            copy($file, $plugin . '/src/' . basename($file, '.txt'));
        }
        $legacyConfig = json_decode(file_get_contents($legacy . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $legacyConfig['version'] = '1.0.3';
        lifecycleJson($plugin . '/composer.json', $legacyConfig);

        foreach (['runtime' => 'RuntimeProvider', 'development' => 'DevelopmentProvider'] as $name => $class) {
            $path = $packages . '/' . $name;
            mkdir($path . '/src', 0775, true);
            lifecycleJson($path . '/composer.json', [
                'name' => 'example/' . $name,
                'version' => '1.0.0',
                'autoload' => ['psr-4' => ['Example\\' => 'src/']],
                'extra' => ['componenta' => ['config-providers' => ['Example\\' . $class]]],
            ]);
            file_put_contents($path . '/src/' . $class . '.php', '<?php namespace Example; final class ' . $class . ' {}');
        }

        lifecycleJson($project . '/composer.json', [
            'name' => 'example/application',
            'require' => ['componenta/composer-plugin' => '^1.0 || ^2.0', 'example/runtime' => '^1.0'],
            'require-dev' => ['example/development' => '^1.0'],
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'path', 'url' => $packages . '/*', 'options' => ['symlink' => false]],
            ],
            'config' => ['allow-plugins' => ['componenta/composer-plugin' => true]],
        ]);
        $file = $project . '/config/componenta-providers.php';
        $read = static function () use ($file): array {
            clearstatcache(true, $file);
            return require $file;
        };
        $checkAutoload = static function (bool $dev) use ($project, $file): void {
            $code = 'require ' . var_export($project . '/vendor/autoload.php', true) . ';'
                . '$classes = require ' . var_export($file, true) . ';'
                . 'foreach ($classes as $class) { if (!class_exists($class)) { exit(1); } }'
                . 'exit(class_exists("Example\\\\DevelopmentProvider") === ' . ($dev ? 'true' : 'false') . ' ? 0 : 2);';
            (new Process([PHP_BINARY, '-r', $code], $project))->mustRun();
        };

        lifecycleCommand($composer, $project, $home, ['install']);
        $both = ['Example\\DevelopmentProvider', 'Example\\RuntimeProvider'];
        $actual = $read();
        sort($actual);
        expect($actual)->toBe($both);
        $checkAutoload(true);

        // Prove that the new entry point was actually loaded when Composer updates
        // from the previous published version, not merely that the lock changed.
        expect(file_get_contents($file))->toContain('::class');
        foreach (glob($repository . '/src/*.php') ?: [] as $source) {
            copy($source, $plugin . '/src/' . basename($source));
        }
        $pluginConfig = json_decode(file_get_contents($repository . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        unset($pluginConfig['require-dev'], $pluginConfig['autoload-dev'], $pluginConfig['scripts'], $pluginConfig['config'], $pluginConfig['extra']['branch-alias']);
        $pluginConfig['version'] = '2.0.0';
        lifecycleJson($plugin . '/composer.json', $pluginConfig);

        lifecycleCommand($composer, $project, $home, ['update', 'componenta/composer-plugin', '--no-audit']);
        $installed = json_decode(file_get_contents($project . '/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($installed['packages'], 'version', 'name')['componenta/composer-plugin'])->toBe('2.0.0');
        $actual = $read();
        sort($actual);
        expect($actual)->toBe($both)
            ->and(file_get_contents($file))->not->toContain('::class')
            ->and(file_get_contents($file))->toContain(var_export('Example\\RuntimeProvider', true));

        lifecycleCommand($composer, $project, $home, ['dump-autoload', '--no-dev']);
        expect($read())->toBe(['Example\\RuntimeProvider']);
        $checkAutoload(false);

        lifecycleCommand($composer, $project, $home, ['dump-autoload', '--dev']);
        $actual = $read();
        sort($actual);
        expect($actual)->toBe($both);
        $checkAutoload(true);

        // A second process with the same dependencies must not change ordering or bytes.
        $stableContents = file_get_contents($file);
        touch($file, time() - 100);
        clearstatcache(true, $file);
        $stableMtime = filemtime($file);
        lifecycleCommand($composer, $project, $home, ['dump-autoload', '--dev']);
        clearstatcache(true, $file);
        expect(file_get_contents($file))->toBe($stableContents)
            ->and(filemtime($file))->toBe($stableMtime);

        // Non-installing commands must not change a dev manifest even with --no-dev.
        touch($file, time() - 100);
        clearstatcache(true, $file);
        $mtime = filemtime($file);
        $contents = file_get_contents($file);

        lifecycleCommand($composer, $project, $home, ['update', '--no-install', '--no-dev', '--no-audit']);
        clearstatcache(true, $file);
        expect(file_get_contents($file))->toBe($contents)
            ->and(filemtime($file))->toBe($mtime);

        lifecycleCommand($composer, $project, $home, ['install', '--download-only', '--no-dev']);
        clearstatcache(true, $file);
        expect(file_get_contents($file))->toBe($contents)
            ->and(filemtime($file))->toBe($mtime);

        // Composer must reload the plugin entry point in the update process.
        $pluginConfig['version'] = '2.0.1';
        lifecycleJson($plugin . '/composer.json', $pluginConfig);
        lifecycleCommand($composer, $project, $home, ['update', 'componenta/composer-plugin']);
        $installed = json_decode(file_get_contents($project . '/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        $versions = array_column($installed['packages'], 'version', 'name');
        expect($versions['componenta/composer-plugin'])->toBe('2.0.1');
        $actual = $read();
        sort($actual);
        expect($actual)->toBe($both);

        lifecycleCommand($composer, $project, $home, ['install', '--no-dev', '--no-autoloader']);
        expect($read())->toBe(['Example\\RuntimeProvider']);
    } finally {
        lifecycleRemoveTree($directory);
    }
});

it('preserves inline alias provider ordering between install and a separate dump-autoload command', function (): void {
    $repository = dirname(__DIR__, 2);
    $composer = $repository . '/tests/Fixtures/composer-cli.php';
    $directory = sys_get_temp_dir() . '/componenta-inline-alias-' . bin2hex(random_bytes(8));
    $project = $directory . '/project';
    $home = $directory . '/home';
    $packages = $directory . '/packages';
    $plugin = $packages . '/plugin';
    mkdir($project, 0775, true);
    mkdir($home, 0775, true);
    mkdir($plugin . '/src', 0775, true);

    try {
        foreach (glob($repository . '/src/*.php') ?: [] as $source) {
            copy($source, $plugin . '/src/' . basename($source));
        }
        $config = json_decode(file_get_contents($repository . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        unset($config['require-dev'], $config['autoload-dev'], $config['scripts'], $config['config'], $config['extra']['branch-alias']);
        $config['version'] = '2.0.0';
        lifecycleJson($plugin . '/composer.json', $config);

        $dependency = $packages . '/dependency';
        $feature = $packages . '/feature';
        mkdir($dependency);
        mkdir($feature);
        lifecycleJson($dependency . '/composer.json', [
            'name' => 'example/z-dependency',
            'version' => 'dev-main',
            'extra' => ['componenta' => ['config-providers' => ['Example\\DependencyProvider']]],
        ]);
        lifecycleJson($feature . '/composer.json', [
            'name' => 'example/a-feature',
            'version' => '1.0.0',
            'require' => ['example/z-dependency' => '^2.0'],
            'extra' => ['componenta' => ['config-providers' => ['Example\\FeatureProvider']]],
        ]);
        lifecycleJson($project . '/composer.json', [
            'name' => 'example/application',
            'require' => [
                'componenta/composer-plugin' => '^2.0',
                'example/a-feature' => '^1.0',
                'example/z-dependency' => 'dev-main as 2.0.x-dev',
            ],
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'path', 'url' => $packages . '/*', 'options' => ['symlink' => false]],
            ],
            'config' => ['allow-plugins' => ['componenta/composer-plugin' => true]],
        ]);

        lifecycleCommand($composer, $project, $home, ['install']);
        $file = $project . '/config/componenta-providers.php';
        expect(require $file)->toBe(['Example\\DependencyProvider', 'Example\\FeatureProvider']);
        $installed = require $project . '/vendor/composer/installed.php';
        expect($installed['versions']['example/z-dependency']['aliases'])->toContain('2.0.x-dev');
        $contents = file_get_contents($file);
        touch($file, time() - 100);
        clearstatcache(true, $file);
        $mtime = filemtime($file);

        lifecycleCommand($composer, $project, $home, ['dump-autoload']);
        clearstatcache(true, $file);
        expect(require $file)->toBe(['Example\\DependencyProvider', 'Example\\FeatureProvider'])
            ->and(file_get_contents($file))->toBe($contents)
            ->and(filemtime($file))->toBe($mtime);

        lifecycleCommand($composer, $project, $home, ['reinstall', 'example/z-dependency']);
        clearstatcache(true, $file);
        expect(require $file)->toBe(['Example\\DependencyProvider', 'Example\\FeatureProvider'])
            ->and(file_get_contents($file))->toBe($contents)
            ->and(filemtime($file))->toBe($mtime);

        lifecycleCommand($composer, $project, $home, ['dump-autoload']);
        clearstatcache(true, $file);
        expect(require $file)->toBe(['Example\\DependencyProvider', 'Example\\FeatureProvider'])
            ->and(file_get_contents($file))->toBe($contents)
            ->and(filemtime($file))->toBe($mtime);
    } finally {
        lifecycleRemoveTree($directory);
    }
});
