<?php

declare(strict_types=1);

use Componenta\ComposerPlugin\ComponentaPlugin;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Composer\Util\Platform;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;

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
    expect(require $this->file)->toBe(['Example\\Incompatible', 'Example\\Provider', 'Example\\Replacer', 'Example\\Feature']);
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
    expect(require $this->file)->toBe(['Example\\Development', 'Example\\Runtime', 'Example\\Root']);

    runProviderGraph($composer, false);
    expect(require $this->file)->toBe(['Example\\Runtime', 'Example\\Root']);

    runProviderGraph($composer, true);
    expect(require $this->file)->toBe(['Example\\Development', 'Example\\Runtime', 'Example\\Root']);
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

it('resolves self.version requirements rewritten by the dependent package alias', function (): void {
    $composer = graphComposer($this->directory, ['componenta' => ['config-providers-file' => $this->file]]);
    $feature = graphPackage('example/feature', 'Example\\Feature', 'dev-main');
    $feature->setRequires(['example/dependency' => new Link(
        'example/feature', 'example/dependency', new Constraint('==', 'dev-main'), 'requires', 'self.version',
    )]);
    $dependency = graphPackage('example/dependency', 'Example\\Dependency', '2.0.9999999.9999999-dev');
    $repository = $composer->getRepositoryManager()->getLocalRepository();
    $repository->addPackage($feature);
    $repository->addPackage(new AliasPackage($feature, '2.0.9999999.9999999-dev', '2.0.x-dev'));
    $repository->addPackage($dependency);

    runProviderGraph($composer, true);
    expect(require $this->file)->toBe(['Example\\Dependency', 'Example\\Feature']);
});

it('resolves relative provider output from the logical project directory of a symlinked composer file', function (): void {
    $metadata = $this->directory . '/metadata';
    mkdir($metadata);
    $manifest = $metadata . '/composer.json';
    file_put_contents($manifest, json_encode([
        'name' => 'example/application',
        'config' => ['vendor-dir' => $this->directory . '/vendor'],
        'extra' => ['componenta' => [
            'config-providers-file' => 'providers.php',
            'config-providers' => ['Example\\RootProvider'],
        ]],
    ], JSON_THROW_ON_ERROR));
    $link = $this->directory . '/composer.json';
    symlink($manifest, $link);

    $cwd = getcwd();
    $previous = Platform::getEnv('COMPOSER');
    try {
        chdir($this->directory);
        Platform::putEnv('COMPOSER', 'composer.json');
        $composer = Factory::create(new NullIO(), $link, true, true);
        runProviderGraph($composer, true);
        expect(is_file($this->file))->toBeTrue()
            ->and(is_file($metadata . '/providers.php'))->toBeFalse();
        expect(require $this->file)->toBe(['Example\\RootProvider']);
    } finally {
        chdir($cwd);
        if ($previous === false) {
            Platform::clearEnv('COMPOSER');
        } else {
            Platform::putEnv('COMPOSER', $previous);
        }
        if (is_file($metadata . '/providers.php')) {
            unlink($metadata . '/providers.php');
        }
        unlink($link);
        unlink($manifest);
        rmdir($metadata);
    }
})->skip(PHP_OS_FAMILY === 'Windows', 'Symlink privileges vary on Windows');

it('skips only final install events in non-installing command modes and resets on a subsequent command', function (): void {
    $composer = graphComposer(
        $this->directory,
        ['componenta' => ['config-providers-file' => $this->file]],
        [],
        ['example/development' => '*'],
    );
    $repository = $composer->getRepositoryManager()->getLocalRepository();
    $repository->addPackage(graphPackage('example/development', 'Example\\Development'));
    $repository->setDevPackageNames(['example/development']);
    $io = new NullIO();
    $plugin = new ComponentaPlugin();
    $plugin->activate($composer, $io);
    $plugin->generateProviders(new Event(ScriptEvents::POST_AUTOLOAD_DUMP, $composer, $io, true));
    expect(require $this->file)->toBe(['Example\\Development']);

    foreach ([
        ['update', 'no-install', ScriptEvents::POST_UPDATE_CMD],
        ['install', 'download-only', ScriptEvents::POST_INSTALL_CMD],
    ] as [$command, $option, $eventName]) {
        $definition = new InputDefinition([new InputOption($option, null, InputOption::VALUE_NONE)]);
        $input = new ArrayInput(['--' . $option => true], $definition);
        $plugin->onCommand(new CommandEvent(PluginEvents::COMMAND, $command, $input, new NullOutput()));
        $plugin->generateProviders(new Event($eventName, $composer, $io, false));
        expect(require $this->file)->toBe(['Example\\Development']);
    }

    $plugin->generateProviders(new Event(ScriptEvents::POST_AUTOLOAD_DUMP, $composer, $io, false));
    expect(require $this->file)->toBe([]);

    $plugin->onCommand(new CommandEvent(
        PluginEvents::COMMAND, 'install', new ArrayInput([], new InputDefinition()), new NullOutput(),
    ));
    $plugin->generateProviders(new Event(ScriptEvents::POST_INSTALL_CMD, $composer, $io, true));
    expect(require $this->file)->toBe(['Example\\Development']);
});

it('keeps the manifest identical when Composer changes repository iteration order', function (): void {
    $extra = ['componenta' => ['config-providers-file' => $this->file]];
    $first = graphComposer($this->directory, $extra);
    $second = graphComposer($this->directory, $extra);
    $a = graphPackage('example/a', 'Example\\A');
    $b = graphPackage('example/b', 'Example\\B');
    $feature = graphPackage('example/feature', 'Example\\Feature');
    $feature->setRequires(['example/b' => graphLink('example/feature', 'example/b', '*')]);

    foreach ([$feature, $b, $a] as $package) {
        $first->getRepositoryManager()->getLocalRepository()->addPackage(clone $package);
    }
    foreach ([$a, $b, $feature] as $package) {
        $second->getRepositoryManager()->getLocalRepository()->addPackage(clone $package);
    }

    runProviderGraph($first, true);
    expect(require $this->file)->toBe(['Example\\A', 'Example\\B', 'Example\\Feature']);
    $contents = file_get_contents($this->file);
    touch($this->file, time() - 100);
    clearstatcache(true, $this->file);
    $mtime = filemtime($this->file);

    runProviderGraph($second, true);
    clearstatcache(true, $this->file);
    expect(file_get_contents($this->file))->toBe($contents)
        ->and(filemtime($this->file))->toBe($mtime);
});
