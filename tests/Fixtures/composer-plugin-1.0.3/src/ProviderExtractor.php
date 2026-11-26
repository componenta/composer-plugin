<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

use UnexpectedValueException;

final readonly class ProviderExtractor
{
    private const string EXTRA_KEY = 'componenta';
    private const string PROVIDERS_KEY = 'config-providers';

    /**
     * @return list<class-string>
     */
    public function extract(ProviderPackage $package): array
    {
        $componenta = $package->extra[self::EXTRA_KEY] ?? null;

        if ($componenta === null) {
            return [];
        }

        if (!is_array($componenta)) {
            throw new UnexpectedValueException(sprintf(
                'Package "%s" extra.componenta must be an object.',
                $package->name,
            ));
        }

        $providers = $componenta[self::PROVIDERS_KEY] ?? [];

        if (!is_array($providers) || !array_is_list($providers)) {
            throw new UnexpectedValueException(sprintf(
                'Package "%s" extra.componenta.config-providers must be a list of class names.',
                $package->name,
            ));
        }

        foreach ($providers as $provider) {
            if (!is_string($provider) || !$this->isClassString($provider)) {
                throw new UnexpectedValueException(sprintf(
                    'Package "%s" contains invalid Componenta config provider.',
                    $package->name,
                ));
            }
        }

        /** @var list<class-string> $providers */
        return $providers;
    }

    private function isClassString(string $class): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $class) === 1;
    }
}
