<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Throwable;
use UnexpectedValueException;

final class ComponentaPlugin implements PluginInterface, EventSubscriberInterface
{
    private const string DEFAULT_PROVIDER_FILE = 'config/componenta-providers.php';
    private const string PACKAGE_NAME = 'componenta/composer-plugin';

    private Composer $composer;
    private IOInterface $io;
    private string $projectRoot;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->projectRoot = $this->resolveProjectRoot($composer);
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // The path is user-controlled. Never delete a potentially user-owned file.
        $io->writeError('<comment>Componenta provider manifest is not removed automatically. Remove it when no longer used by the application.</comment>');
    }

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
            if (!$this->isEnabledForProject()) {
                return;
            }

            // Composer passes the autoload/install mode in Script\Event.
            // Manual calls without an event retain the installed-set behavior.
            $devMode = !$event instanceof Event || $event->isDevMode();
            $providers = new ComposerPackageGraph()->collect($this->composer, $devMode);
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

    private function isEnabledForProject(): bool
    {
        $root = $this->composer->getPackage();
        $componenta = $root->getExtra()['componenta'] ?? null;
        if (is_array($componenta) && array_key_exists('config-providers-file', $componenta)) {
            return true;
        }

        if (isset($root->getRequires()[self::PACKAGE_NAME]) || isset($root->getDevRequires()[self::PACKAGE_NAME])) {
            return true;
        }

        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {
            if ($package->getName() === self::PACKAGE_NAME) {
                return true;
            }
        }

        // A globally installed plugin must not create files in unrelated projects.
        return false;
    }

    private function providerFilePath(): string
    {
        $extra = $this->composer->getPackage()->getExtra();
        $componenta = $extra['componenta'] ?? [];
        if (array_key_exists('componenta', $extra) && !is_array($extra['componenta'])) {
            throw new UnexpectedValueException('Root extra.componenta must be an object.');
        }
        $file = array_key_exists('config-providers-file', $componenta)
            ? $componenta['config-providers-file']
            : self::DEFAULT_PROVIDER_FILE;

        if (!is_string($file) || trim($file) === '' || str_contains($file, "\0")
            || preg_match('~\A[a-z][a-z0-9+.-]*://~i', $file) === 1) {
            throw new UnexpectedValueException('extra.componenta.config-providers-file must be a non-empty local path.');
        }

        if ($this->isAbsolutePath($file)) {
            return $file;
        }

        return $this->projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
    }

    private function resolveProjectRoot(Composer $composer): string
    {
        $config = $composer->getConfig();
        $source = $config->getConfigSource()->getName();
        // Factory uses the global config source when initialized from an array.
        // For file-backed instances prefer the actual loaded composer.json.
        $globalConfig = $config->get('home') . '/config.json';
        $composerFile = $source !== $globalConfig && is_file($source)
            ? $source
            : Factory::getComposerFile();
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
