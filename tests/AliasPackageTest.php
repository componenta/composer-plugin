<?php

declare(strict_types=1);

use Componenta\ComposerPlugin\ComponentaPlugin;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Semver\Constraint\Constraint;

it('generates each provider once when Composer exposes version aliases', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-plugin-alias-' . bin2hex(random_bytes(8));
    mkdir($directory);
    $file = $directory . '/providers.php';
    try {
        $composer = Factory::create(new NullIO(), [
            'name' => 'example/application',
            'require' => [],
            'config' => ['vendor-dir' => $directory . '/vendor'],
            'extra' => ['componenta' => ['config-providers-file' => $file]],
        ], true, true);
        $dependency = new CompletePackage('example/dependency', 'dev-main', 'dev-main');
        $dependency->setExtra(['componenta' => ['config-providers' => ['Example\\DependencyProvider']]]);
        $package = new CompletePackage('example/feature', 'dev-main', 'dev-main');
        $package->setExtra(['componenta' => ['config-providers' => ['Example\\FeatureProvider']]]);
        $package->setRequires(['example/dependency' => new Link(
            'example/feature', 'example/dependency', new Constraint('==', 'dev-main'), 'requires', 'dev-main',
        )]);
        $repository = $composer->getRepositoryManager()->getLocalRepository();
        $repository->addPackage($package);
        $repository->addPackage(new AliasPackage($package, '2.0.9999999.9999999-dev', '2.0.x-dev'));
        $repository->addPackage($dependency);
        $plugin = new ComponentaPlugin();
        $plugin->activate($composer, new NullIO());
        $plugin->generateProviders();

        expect(require $file)->toBe(['Example\\DependencyProvider', 'Example\\FeatureProvider']);
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
        rmdir($directory);
    }
});
