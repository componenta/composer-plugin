<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

use PhpToken;
use UnexpectedValueException;

/** Reads Composer's generated array literal without executing PHP. @internal */
final readonly class InstalledMetadataReader
{
    /** @return array<string, mixed> */
    public function versions(string $file): array
    {
        if (!is_file($file)) {
            if (file_exists($file) || is_link($file)) {
                throw new UnexpectedValueException(sprintf('Composer metadata "%s" must be a regular file.', $file));
            }

            return [];
        }

        $source = @file_get_contents($file);
        if ($source === false) {
            throw new UnexpectedValueException(sprintf('Unable to read Composer metadata "%s".', $file));
        }

        try {
            $tokens = array_values(array_filter(
                PhpToken::tokenize($source),
                static fn(PhpToken $token): bool => !in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
            ));
            $offset = 0;
            $this->expect($tokens, $offset, T_OPEN_TAG);
            $this->expect($tokens, $offset, T_RETURN);
            $data = $this->value($tokens, $offset, dirname(realpath($file) ?: $file), 0);
            $this->expect($tokens, $offset, ord(';'));
            if ($offset !== count($tokens) || !is_array($data) || !is_array($data['versions'] ?? null)) {
                throw new UnexpectedValueException('Expected a single returned array containing versions.');
            }

            foreach ($data['versions'] as $name => $record) {
                if (!is_string($name) || $name === '' || !is_array($record)) {
                    throw new UnexpectedValueException('Expected a map of package names to metadata records.');
                }
            }

            return $data['versions'];
        } catch (UnexpectedValueException $exception) {
            throw new UnexpectedValueException(
                sprintf('Invalid Composer installed.php metadata in "%s": %s', $file, $exception->getMessage()),
                0,
                $exception,
            );
        }
    }

    /** @param list<PhpToken> $tokens */
    private function value(array $tokens, int &$offset, string $directory, int $depth): array|string|int|bool|null
    {
        if ($depth > 16 || !isset($tokens[$offset])) {
            throw new UnexpectedValueException('Unexpected end or nesting of metadata.');
        }

        $token = $tokens[$offset++];
        if ($token->id === T_ARRAY || $token->id === ord('[')) {
            $end = $token->id === T_ARRAY ? ord(')') : ord(']');
            if ($token->id === T_ARRAY) {
                $this->expect($tokens, $offset, ord('('));
            }

            $result = [];
            while (!$this->accept($tokens, $offset, $end)) {
                $item = $this->value($tokens, $offset, $directory, $depth + 1);
                if ($this->accept($tokens, $offset, T_DOUBLE_ARROW)) {
                    if ((!is_string($item) && !is_int($item)) || array_key_exists($item, $result)) {
                        throw new UnexpectedValueException('Invalid or duplicate metadata array key.');
                    }
                    $result[$item] = $this->value($tokens, $offset, $directory, $depth + 1);
                } else {
                    if (array_key_exists(PHP_INT_MAX, $result)) {
                        throw new UnexpectedValueException('Metadata array index overflow.');
                    }
                    $result[] = $item;
                }
                if (!$this->accept($tokens, $offset, ord(','))) {
                    $this->expect($tokens, $offset, $end);
                    break;
                }
            }

            return $result;
        }

        if ($token->id === T_LNUMBER && (string) (int) $token->text === $token->text) {
            return (int) $token->text;
        }
        if ($token->id === T_STRING) {
            return match (strtolower($token->text)) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => throw new UnexpectedValueException('Unexpected metadata constant.'),
            };
        }

        if ($token->id === T_CONSTANT_ENCAPSED_STRING || $token->id === T_DIR) {
            $value = $token->id === T_DIR ? $directory : $this->literal($token->text);
            while ($this->accept($tokens, $offset, ord('.'))) {
                $value .= $this->literal($this->expect($tokens, $offset, T_CONSTANT_ENCAPSED_STRING)->text);
            }

            return $value;
        }

        throw new UnexpectedValueException('Unexpected token in metadata array.');
    }

    private function literal(string $text): string
    {
        // var_export() emits a double-quoted NUL fragment between single-quoted strings.
        if ($text === '"\0"') {
            return "\0";
        }
        if (!str_starts_with($text, "'") || !str_ends_with($text, "'")) {
            throw new UnexpectedValueException('Unexpected metadata string literal.');
        }

        return strtr(substr($text, 1, -1), ["\\\\" => "\\", "\\'" => "'"]);
    }

    /** @param list<PhpToken> $tokens */
    private function accept(array $tokens, int &$offset, int $id): bool
    {
        if (($tokens[$offset]->id ?? null) !== $id) {
            return false;
        }

        ++$offset;
        return true;
    }

    /** @param list<PhpToken> $tokens */
    private function expect(array $tokens, int &$offset, int $id): PhpToken
    {
        if (!$this->accept($tokens, $offset, $id)) {
            throw new UnexpectedValueException('Unexpected metadata syntax.');
        }

        return $tokens[$offset - 1];
    }
}
