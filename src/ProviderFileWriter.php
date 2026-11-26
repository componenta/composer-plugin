<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

use RuntimeException;

final readonly class ProviderFileWriter
{
    /**
     * @param list<class-string> $providers
     */
    public function write(string $file, array $providers): bool
    {
        if ($file === '' || str_contains($file, "\0") || preg_match('~\A[a-z][a-z0-9+.-]*://~i', $file) === 1) {
            throw new RuntimeException('Provider file must be a local filesystem path.');
        }

        $contents = (new ProviderFileRenderer())->render($providers);
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }

        clearstatcache(true, $file);
        if (is_link($file) || (file_exists($file) && !is_file($file))) {
            throw new RuntimeException(sprintf('Provider file "%s" must be a regular file, not a link or directory.', $file));
        }

        $mode = 0666 & ~umask();
        if (is_file($file)) {
            $previous = @file_get_contents($file);
            if ($previous === false) {
                throw new RuntimeException(sprintf('Unable to read provider file "%s".', $file));
            }

            if ($previous === $contents) {
                return false;
            }

            $permissions = @fileperms($file);
            if ($permissions === false) {
                throw new RuntimeException(sprintf('Unable to read permissions of "%s".', $file));
            }

            $mode = $permissions & 0777;
        }

        $directory = realpath($directory);
        if ($directory === false) {
            throw new RuntimeException(sprintf('Unable to resolve directory for "%s".', $file));
        }
        $tmp = @tempnam($directory, '.componenta-providers-');
        if ($tmp === false) {
            throw new RuntimeException(sprintf('Unable to create temporary file for "%s".', $file));
        }

        try {
            // tempnam() may fall back to the system temporary directory.
            if (realpath(dirname($tmp)) !== $directory) {
                throw new RuntimeException(sprintf('Temporary file for "%s" is outside the destination directory.', $file));
            }

            if (@file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException(sprintf('Unable to write complete temporary file for "%s".', $file));
            }

            if (!@chmod($tmp, $mode)) {
                throw new RuntimeException(sprintf('Unable to set permissions for "%s".', $file));
            }

            if (!@rename($tmp, $file)) {
                throw new RuntimeException(sprintf('Unable to replace "%s".', $file));
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        clearstatcache(true, $file);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        return true;
    }
}
