<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

final readonly class ProviderCollector
{
    /**
     * @param iterable<ProviderPackage> $packages
     * @return list<class-string>
     */
    public function collect(iterable $packages): array
    {
        $providers = [];
        $extractor = new ProviderExtractor();

        foreach ($packages as $package) {
            foreach ($extractor->extract($package) as $provider) {
                $providers[$provider] = $provider;
            }
        }

        return array_values($providers);
    }
}
