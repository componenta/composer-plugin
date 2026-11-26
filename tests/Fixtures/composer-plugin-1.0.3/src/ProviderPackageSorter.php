<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

final readonly class ProviderPackageSorter
{
    /**
     * Dependencies are visited before their dependants while the original
     * package order remains the tie-breaker for unrelated packages.
     *
     * @param iterable<ProviderPackage> $packages
     * @param array<string, list<string>> $requires
     * @return list<ProviderPackage>
     */
    public function sort(iterable $packages, array $requires): array
    {
        /** @var array<string, ProviderPackage> $byName */
        $byName = [];
        /** @var list<string> $order */
        $order = [];

        foreach ($packages as $package) {
            if (isset($byName[$package->name])) {
                throw new \UnexpectedValueException(sprintf(
                    'Duplicate Composer package "%s" while collecting Componenta providers.',
                    $package->name,
                ));
            }

            $byName[$package->name] = $package;
            $order[] = $package->name;
        }

        $extractor = new ProviderExtractor();
        /** @var array<string, true> $providerNames */
        $providerNames = [];

        foreach ($order as $name) {
            if ($extractor->extract($byName[$name]) !== []) {
                $providerNames[$name] = true;
            }
        }

        /** @var array<string, list<string>> $dependencies */
        $dependencies = [];

        foreach (array_keys($providerNames) as $name) {
            $dependencies[$name] = $this->findProviderDependencies($name, $byName, $providerNames, $requires);
        }

        /** @var array<string, 1|2> $state */
        $state = [];
        /** @var list<ProviderPackage> $sorted */
        $sorted = [];

        foreach ($order as $name) {
            if (isset($providerNames[$name])) {
                $this->visit($name, $byName, $dependencies, $state, $sorted);
            }
        }

        return $sorted;
    }

    /**
     * @param array<string, ProviderPackage> $packages
     * @param array<string, true> $providerNames
     * @param array<string, list<string>> $requires
     * @return list<string>
     */
    private function findProviderDependencies(
        string $name,
        array $packages,
        array $providerNames,
        array $requires,
    ): array {
        /** @var array<string, true> $visited */
        $visited = [$name => true];
        /** @var list<string> $queue */
        $queue = $requires[$name] ?? [];
        /** @var list<string> $dependencies */
        $dependencies = [];

        for ($offset = 0; isset($queue[$offset]); $offset++) {
            $dependency = $queue[$offset];

            if (isset($visited[$dependency])) {
                continue;
            }

            $visited[$dependency] = true;

            if (!isset($packages[$dependency])) {
                continue;
            }

            if (isset($providerNames[$dependency])) {
                $dependencies[] = $dependency;
            }

            foreach ($requires[$dependency] ?? [] as $transitiveDependency) {
                $queue[] = $transitiveDependency;
            }
        }

        return $dependencies;
    }

    /**
     * @param array<string, ProviderPackage> $packages
     * @param array<string, list<string>> $dependencies
     * @param array<string, 1|2> $state
     * @param list<ProviderPackage> $sorted
     */
    private function visit(
        string $name,
        array $packages,
        array $dependencies,
        array &$state,
        array &$sorted,
    ): void {
        if (($state[$name] ?? null) === 2) {
            return;
        }

        if (($state[$name] ?? null) === 1) {
            throw new \UnexpectedValueException(sprintf(
                'Circular Composer dependency involving "%s" while collecting Componenta providers.',
                $name,
            ));
        }

        $state[$name] = 1;
        $package = $packages[$name];

        foreach ($dependencies[$name] as $dependency) {
            $this->visit($dependency, $packages, $dependencies, $state, $sorted);
        }

        $state[$name] = 2;
        $sorted[] = $package;
    }
}
