<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

final readonly class ProviderPackageSorter
{
    /**
     * Order provider-bearing packages after their provider-bearing dependencies.
     * Among currently eligible packages, the original input order wins.
     *
     * @param iterable<ProviderPackage> $packages
     * @param array<string, list<string>> $requires
     * @return list<ProviderPackage>
     */
    public function sort(iterable $packages, array $requires): array
    {
        return new ProviderManifest($packages)->orderedPackages($requires);
    }
}
