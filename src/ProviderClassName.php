<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

/** @internal */
final class ProviderClassName
{
    public static function isValid(string $name): bool
    {
        $parts = explode('\\', $name);
        foreach ($parts as $part) {
            if (preg_match('/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/', $part) !== 1) {
                return false;
            }
        }

        // Relative class names and reserved type names cannot name a provider.
        $last = strtolower($parts[count($parts) - 1]);

        return !in_array($last, [
            'self', 'parent', 'static', 'int', 'float', 'bool', 'string',
            'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed',
            'never', 'array', 'callable', 'namespace', 'class', 'interface',
            'trait', 'extends', 'implements', 'function', 'enum', 'match',
        ], true);
    }
}
