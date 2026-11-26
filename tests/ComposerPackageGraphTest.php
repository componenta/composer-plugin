<?php

declare(strict_types=1);

use Componenta\ComposerPlugin\ComponentaPlugin;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Semver\VersionParser;

function graphPackage(string $name, string $provider, string $version = '1.0.0.0'): CompletePackage
{
    $package = new CompletePackage($name, $version, $version);
    $package->setExtra(['componenta' => ['config-providers' => [$provider]]]);
    return $package;
}

function graphLink(string $source, string $target, string $constraint, string $description = 'requires'): Link
{
    return new Link($source, $target, (new VersionParser())->parseConstraints($constraint), $description, $constraint);
}

function graphComposer(string $directory, array $extra = [], array $require = [], array $requireDev = []): Composer
{
    return Factory::create(new NullIO(), [
        'name' => 'example/application',
        'require' => $require,
        'require-dev' => $requireDev,
        'config' => ['vendor-dir' => $directory . '/vendor'],
        'extra' => $extra,
    ], true, true);
}

function runProviderGraph(Composer $composer, bool $devMode): void
{
    $io = new NullIO();
    $plugin = new ComponentaPlugin();
    $plugin->activate($composer, $io);
    $plugin->generateProviders(new Event(ScriptEvents::POST_AUTOLOAD_DUMP, $composer, $io, $devMode));
}

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . '/componenta-graph-' . bin2hex(random_bytes(8));
    mkdir($this->directory);
    $this->file = $this->directory . '/providers.php';
});

afterEach(function (): void {
    if (is_file($this->file)) {
        unlink($this->file);
    }
    rmdir($this->directory);
});

it('orders compatible replacers and virtual providers before dependent packages', function (): void {
    $composer = graphComposer($this->directory, ['componenta' => ['config-providers-file' => $this->file]]);
    $feature = graphPackage('example/feature', 'Example\\Feature');
    $feature->setRequires([
        'example/contract' => graphLink('example/feature', 'example/contract', '^2.0'),
        'example/legacy' => graphLink('example/feature', 'example/legacy', '^1.0'),
    ]);
    $provider = graphPackage('example/provider', 'Example\\Provider');
    $provider->setProvides(['example/contract' => graphLink('example/provider', 'example/contract', '2.0', 'provides')]);
    $replacer = graphPackage('example/replacer', 'Example\\Replacer');
    $replacer->setReplaces(['example/legacy' => graphLink('example/replacer', 'example/legacy', '1.0', 'replaces')]);
    $incompatible = graphPackage('example/incompatible', 'Example\\Incompatible');
    $incompatible->setProvides(['example/contract' => graphLink('example/incompatible', 'example/contract', '1.0', 'provides')]);
    $repository = $composer->getRepositoryManager()->getLocalRepository();
    foreach ([$feature, $provider, $replacer, $incompatible] as $package) {
        $repository->addPackage($package);
    }

    runProviderGraph($composer, true);
    expect(require $this->file)->toBe(['Example\\Provider', 'Example\\Replacer', 'Example\\Feature', 'Example\\Incompatible']);
});

it('resolves dependency constraints against Composer version aliases', function (): void {
    $composer = graphComposer($this->directory, ['componenta' => ['config-providers-file' => $this->file]]);
    $feature = graphPackage('example/feature', 'Example\\Feature');
    $feature->setRequires(['example/dependency' => graphLink('example/feature', 'example/dependency', '^2.0@dev')]);
    $dependency = graphPackage('example/dependency', 'Example\\Dependency', 'dev-main');
    $repository = $composer->getRepositoryManager()->getLocalRepository();
    $repository->addPackage($feature);
    $repository->addPackage($dependency);
    $repository->addPackage(new AliasPackage($dependency, '2.0.9999999.9999999-dev', '2.0.x-dev'));

    runProviderGraph($composer, true);
    expect(require $this->file)->toBe(['Example\\Dependency', 'Example\\Feature']);
});

it('switches the manifest between dev and no-dev autoload modes', function (): void {
    $composer = graphComposer(
        $this->directory,
        ['componenta' => ['config-providers-file' => $this->file, 'config-providers' => ['Example\\Root']]],
        ['example/runtime' => '*'],
        ['example/development' => '*'],
    );
    $repository = $composer->getRepositoryManager()->getLocalRepository();
    $repository->addPackage(graphPackage('example/runtime', 'Example\\Runtime'));
    $repository->addPackage(graphPackage('example/development', 'Example\\Development'));
    $repository->setDevPackageNames(['example/development']);

    runProviderGraph($composer, true);
    expect(require $this->file)->toBe(['Example\\Runtime', 'Example\\Development', 'Example\\Root']);

    runProviderGraph($composer, false);
    expect(require $this->file)->toBe(['Example\\Runtime', 'Example\\Root']);

    runProviderGraph($composer, true);
    expect(require $this->file)->toBe(['Example\\Runtime', 'Example\\Development', 'Example\\Root']);
});

it('uses the Composer legacy reachability fallback for missing dev package metadata', function (): void {
    $composer = graphComposer(
        $this->directory,
        ['componenta' => ['config-providers-file' => $this->file]],
        ['example/runtime' => '*'],
        ['example/development' => '*'],
    );
    $repository = $composer->getRepositoryManager()->getLocalRepository();
    $repository->addPackage(graphPackage('example/runtime', 'Example\\Runtime'));
    $repository->addPackage(graphPackage('example/development', 'Example\\Development'));
    $repository->setDevPackageNames([]);

    runProviderGraph($composer, false);
    expect(require $this->file)->toBe(['Example\\Runtime']);
});

it('does not generate a default manifest for an unrelated project', function (): void {
    $cwd = getcwd();
    chdir($this->directory);
    try {
        $composer = graphComposer($this->directory);
        runProviderGraph($composer, true);
        expect(file_exists($this->directory . '/config/componenta-providers.php'))->toBeFalse();
    } finally {
        chdir($cwd);
        // Leave a failing regression test clean as well.
        if (is_file($this->directory . '/config/componenta-providers.php')) {
            unlink($this->directory . '/config/componenta-providers.php');
            rmdir($this->directory . '/config');
        }
    }
});

it('rejects an explicitly null output path without creating a file', function (): void {
    $composer = graphComposer($this->directory, ['componenta' => ['config-providers-file' => null]]);
    expect(fn() => runProviderGraph($composer, true))->toThrow(UnexpectedValueException::class);
    expect(file_exists($this->file))->toBeFalse();
});
