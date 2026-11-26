<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
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
    private bool $skipInstallEvents = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->projectRoot = $this->resolveProjectRoot($composer);
        $this->skipInstallEvents = false;
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
            PluginEvents::COMMAND => 'onCommand',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'generateProviders',
            ScriptEvents::POST_INSTALL_CMD => 'generateProviders',
            ScriptEvents::POST_UPDATE_CMD => 'generateProviders',
        ];
    }

    public function onCommand(CommandEvent $event): void
    {
        $input = $event->getInput();
        // These modes can dispatch final script events without installing packages
        // or dumping the autoloader. Leave the existing manifest intact.
        $this->skipInstallEvents =
            ($input->hasOption('no-install') && $input->getOption('no-install') === true)
            || ($input->hasOption('download-only') && $input->getOption('download-only') === true);
    }

    public function generateProviders(mixed $event = null): void
    {
        if ($this->skipInstallEvents && $event instanceof Event
            && in_array($event->getName(), [ScriptEvents::POST_INSTALL_CMD, ScriptEvents::POST_UPDATE_CMD], true)) {
            return;
        }

        try {
            if (!$this->isEnabledForProject()) {
                return;
            }

            // Composer passes the autoload/install mode in Script\Event.
            // Manual calls without an event retain the installed-set behavior.
            $devMode = !($event instanceof Event) || $event->isDevMode();
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
        if (array_key_exists('componenta', $extra)
            && (!is_array($extra['componenta']) || ($componenta !== [] && array_is_list($componenta)))) {
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
        // Composer stores a realpath in its file-backed config source, which
        // can point through a symlink outside the application directory.
        // Preserve the CLI manifest's logical location when it identifies
        // the same file. For a different programmatic source, use that source.
        $composerFile = Factory::getComposerFile();
        $globalConfig = $config->get('home') . '/config.json';
        if ($source !== $globalConfig && is_file($source)
            && realpath($composerFile) !== realpath($source)) {
            $composerFile = $source;
        }
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
