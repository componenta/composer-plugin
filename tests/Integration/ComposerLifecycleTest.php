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
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER_NO_INTERACTION' => '1',
        ],
        null,
        120,
    );
    $process->mustRun();
}

it('generates manifests across real Composer installation, dump-autoload, update and no-dev installation', function (): void {
    $repository = dirname(__DIR__, 2);
    $composer = $repository . '/vendor/bin/composer';
    $directory = sys_get_temp_dir() . '/componenta-lifecycle-' . bin2hex(random_bytes(8));
    $project = $directory . '/project';
    $home = $directory . '/home';
    $packages = $directory . '/packages';
    $plugin = $packages . '/plugin';
    mkdir($project, 0775, true);
    mkdir($home, 0775, true);
    mkdir($plugin . '/src', 0775, true);

    try {
        foreach (glob($repository . '/src/*.php') ?: [] as $file) {
            copy($file, $plugin . '/src/' . basename($file));
        }
        $pluginConfig = json_decode(file_get_contents($repository . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        unset($pluginConfig['require-dev'], $pluginConfig['autoload-dev'], $pluginConfig['scripts'], $pluginConfig['config'], $pluginConfig['extra']['branch-alias']);
        $pluginConfig['version'] = '2.0.0';
        lifecycleJson($plugin . '/composer.json', $pluginConfig);

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
            'require' => ['componenta/composer-plugin' => '^2.0', 'example/runtime' => '^1.0'],
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

        lifecycleCommand($composer, $project, $home, ['install']);
        $both = ['Example\\DevelopmentProvider', 'Example\\RuntimeProvider'];
        $actual = $read();
        sort($actual);
        expect($actual)->toBe($both);

        lifecycleCommand($composer, $project, $home, ['dump-autoload', '--no-dev']);
        expect($read())->toBe(['Example\\RuntimeProvider']);

        lifecycleCommand($composer, $project, $home, ['dump-autoload', '--dev']);
        $actual = $read();
        sort($actual);
        expect($actual)->toBe($both);

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
