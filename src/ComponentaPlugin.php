<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Throwable;

final class ComponentaPlugin implements PluginInterface, EventSubscriberInterface
{
    private const string DEFAULT_PROVIDER_FILE = 'config/componenta-providers.php';

    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => 'generateProviders',
            ScriptEvents::POST_INSTALL_CMD => 'generateProviders',
            ScriptEvents::POST_UPDATE_CMD => 'generateProviders',
        ];
    }

    public function generateProviders(mixed $event = null): void
    {
        try {
            $providers = $this->collectProviders();
            $target = $this->providerFilePath();
            $written = new ProviderFileWriter()->write($target, $providers);

            if ($written) {
                $this->io->write(sprintf('<info>Generated Componenta providers: %s</info>', $target));
            }
        } catch (Throwable $exception) {
            $this->io->writeError(sprintf(
                '<error>Unable to generate Componenta providers: %s</error>',
                $exception->getMessage(),
            ));

            throw $exception;
        }
    }

    /**
     * @return list<class-string>
     */
    private function collectProviders(): array
    {
        $collector = new ProviderCollector();
        $packages = [$this->composer->getPackage()];

        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $packages[] = $package;
        }

        return $collector->collect(array_map(
            static fn(PackageInterface $package): ProviderPackage => new ProviderPackage(
                name: $package->getName(),
                extra: $package->getExtra(),
            ),
            $packages,
        ));
    }

    private function providerFilePath(): string
    {
        $extra = $this->composer->getPackage()->getExtra();
        $file = $extra['componenta']['config-providers-file'] ?? self::DEFAULT_PROVIDER_FILE;

        if (!is_string($file) || trim($file) === '') {
            throw new \UnexpectedValueException('extra.componenta.config-providers-file must be a non-empty string.');
        }

        if ($this->isAbsolutePath($file)) {
            return $file;
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
    }

    private function projectRoot(): string
    {
        $composerFile = Factory::getComposerFile();
        $root = realpath(dirname($composerFile));

        return $root === false ? dirname($composerFile) : $root;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
