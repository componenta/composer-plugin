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
        if (!array_key_exists(self::EXTRA_KEY, $package->extra)) {
            return [];
        }

        $componenta = $package->extra[self::EXTRA_KEY];

        if (!is_array($componenta) || ($componenta !== [] && array_is_list($componenta))) {
            throw new UnexpectedValueException(sprintf(
                'Package "%s" extra.componenta must be an object.',
                $package->name,
            ));
        }

        $providers = array_key_exists(self::PROVIDERS_KEY, $componenta)
            ? $componenta[self::PROVIDERS_KEY]
            : [];

        if (!is_array($providers) || !array_is_list($providers)) {
            throw new UnexpectedValueException(sprintf(
                'Package "%s" extra.componenta.config-providers must be a list of class names.',
                $package->name,
            ));
        }

        foreach ($providers as $provider) {
            if (!is_string($provider) || !ProviderClassName::isValid($provider)) {
                throw new UnexpectedValueException(sprintf(
                    'Package "%s" contains invalid Componenta config provider.',
                    $package->name,
                ));
            }
        }

        /** @var list<class-string> $providers */
        return $providers;
    }
}
