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
            'abstract', 'and', 'array', 'as', 'break', 'callable', 'case',
            'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default',
            'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare',
            'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'eval',
            'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach',
            'function', 'global', 'goto', 'if', 'implements', 'include',
            'include_once', 'instanceof', 'insteadof', 'interface', 'isset',
            'list', 'match', 'namespace', 'new', 'or', 'print', 'private',
            'protected', 'public', 'readonly', 'require', 'require_once',
            'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset',
            'use', 'var', 'while', 'xor', 'yield', '__halt_compiler',
            '__class__', '__dir__', '__file__', '__function__', '__line__',
            '__method__', '__namespace__', '__trait__', '__property__',
            'self', 'parent', 'true', 'false', 'null', 'int', 'float', 'bool',
            'string', 'void', 'iterable', 'object', 'mixed', 'never',
        ], true);
    }
}
