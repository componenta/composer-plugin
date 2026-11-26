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
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }

        $contents = (new ProviderFileRenderer())->render($providers);

        if (is_file($file) && file_get_contents($file) === $contents) {
            return false;
        }

        $tmp = tempnam($directory, basename($file) . '.');

        if ($tmp === false) {
            throw new RuntimeException(sprintf('Unable to create temporary file for "%s".', $file));
        }

        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            @unlink($tmp);

            throw new RuntimeException(sprintf('Unable to write temporary file for "%s".', $file));
        }

        if (!rename($tmp, $file)) {
            @unlink($tmp);

            throw new RuntimeException(sprintf('Unable to write "%s".', $file));
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }

        return true;
    }
}
