<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

use SplPriorityQueue;
use UnexpectedValueException;

/**
 * Validated snapshot of package metadata. Provider reachability stops at the next
 * provider package; transitive ordering is handled by the topological pass.
 *
 * @internal
 */
final readonly class ProviderManifest
{
    /** @var array<string, ProviderPackage> */
    private array $packages;

    /** @var array<string, list<class-string>> */
    private array $providers;

    /** @param iterable<ProviderPackage> $packages */
    public function __construct(iterable $packages)
    {
        $byName = [];
        $providers = [];
        $extractor = new ProviderExtractor();

        foreach ($packages as $package) {
            if (isset($byName[$package->name])) {
                throw new UnexpectedValueException(sprintf(
                    'Duplicate Composer package "%s" while collecting Componenta providers.',
                    $package->name,
                ));
            }

            $byName[$package->name] = $package;
            $classes = $extractor->extract($package);
            if ($classes !== []) {
                $providers[$package->name] = $classes;
            }
        }

        $this->packages = $byName;
        $this->providers = $providers;
    }

    /**
     * @param array<string, list<string>> $requires
     * @return list<ProviderPackage>
     */
    public function orderedPackages(array $requires): array
    {
        $remaining = [];
        $dependants = [];
        $positions = [];
        $queue = new SplPriorityQueue();
        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);

        foreach (array_keys($this->providers) as $position => $name) {
            $positions[$name] = $position;
            $dependencies = $this->providerDependencies($name, $requires);
            $remaining[$name] = count($dependencies);
            foreach ($dependencies as $dependency) {
                $dependants[$dependency][] = $name;
            }
            if ($dependencies === []) {
                $queue->insert($name, -$position);
            }
        }

        $ordered = [];
        while (!$queue->isEmpty()) {
            $name = $queue->extract();
            $ordered[] = $this->packages[$name];
            foreach ($dependants[$name] ?? [] as $dependant) {
                if (--$remaining[$dependant] === 0) {
                    $queue->insert($dependant, -$positions[$dependant]);
                }
            }
        }

        if (count($ordered) !== count($this->providers)) {
            $blocked = array_keys(array_filter($remaining, static fn(int $count): bool => $count > 0));
            throw new UnexpectedValueException(
                'Circular Composer dependency blocks Componenta provider ordering: ' . implode(', ', $blocked),
            );
        }

        return $ordered;
    }

    /**
     * @param array<string, list<string>> $requires
     * @return list<class-string>
     */
    public function collect(array $requires): array
    {
        $classes = [];
        foreach ($this->orderedPackages($requires) as $package) {
            foreach ($this->providers[$package->name] as $class) {
                $classes[strtolower($class)] ??= $class;
            }
        }

        return array_values($classes);
    }

    /**
     * @param array<string, list<string>> $requires
     * @return list<string>
     */
    private function providerDependencies(string $name, array $requires): array
    {
        $visited = [$name => true];
        $pending = $requires[$name] ?? [];
        $dependencies = [];

        while ($pending !== []) {
            $dependency = array_pop($pending);
            if (isset($visited[$dependency])) {
                continue;
            }
            $visited[$dependency] = true;

            if (!isset($this->packages[$dependency])) {
                continue;
            }
            if (isset($this->providers[$dependency])) {
                $dependencies[] = $dependency;
                continue;
            }

            foreach ($requires[$dependency] ?? [] as $transitive) {
                if (!isset($visited[$transitive])) {
                    $pending[] = $transitive;
                }
            }
        }

        return $dependencies;
    }
}
