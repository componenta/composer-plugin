<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

use Composer\Composer;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use UnexpectedValueException;

/** @internal */
final readonly class ComposerPackageGraph
{
    /** @return list<class-string> */
    public function collect(Composer $composer, bool $devMode): array
    {
        $root = $composer->getPackage();
        $repository = $composer->getRepositoryManager()->getLocalRepository();
        $packages = [$root->getName() => $root];

        foreach ($repository->getCanonicalPackages() as $package) {
            if (isset($packages[$package->getName()])) {
                throw new UnexpectedValueException('Duplicate Composer package "' . $package->getName() . '".');
            }
            $packages[$package->getName()] = $package;
        }

        if (!$devMode) {
            $devNames = $repository->getDevPackageNames();
            if ($devNames !== []) {
                $packages = array_diff_key($packages, array_fill_keys($devNames, true));
                $packages = [$root->getName() => $root] + $packages;
            } else {
                $packages = $this->filterLegacyProductionPackages($root, $packages);
            }
        }

        // Aliases are additional version candidates, not separate provider records.
        $candidates = array_values($packages);
        foreach ($repository->getPackages() as $package) {
            if ($package instanceof AliasPackage && isset($packages[$package->getName()])) {
                $candidates[] = $package;
            }
        }
        if ($root instanceof AliasPackage) {
            $candidates[] = $root->getAliasOf();
        }

        $direct = [];
        $virtual = [];
        $requirements = [];
        foreach ($candidates as $package) {
            $name = $package->getName();
            $direct[$name][$name][] = new Constraint('==', $package->getVersion());
            $links = array_values($package->getRequires());
            if ($name === $root->getName() && $devMode) {
                $links = [...$links, ...array_values($package->getDevRequires())];
            }
            foreach ($links as $link) {
                // The canonical record and its aliases can express different
                // self.version constraints for the same requirement.
                $requirements[$name][$link->getTarget()][] = $link->getConstraint();
            }
            foreach ([...array_values($package->getProvides()), ...array_values($package->getReplaces())] as $link) {
                $virtual[$link->getTarget()][$name][] = $link->getConstraint();
            }
        }

        $requires = [];
        $metadata = [];
        foreach ($packages as $name => $package) {
            $metadata[] = new ProviderPackage($name, $package->getExtra());
            $dependencies = [];
            foreach ($requirements[$name] ?? [] as $target => $constraints) {
                $matches = $this->matchingPackages($constraints, $direct[$target] ?? []);
                if ($matches === []) {
                    $matches = $this->matchingPackages($constraints, $virtual[$target] ?? []);
                }

                // A package satisfying its own virtual requirement needs no edge.
                if (in_array($name, $matches, true)) {
                    continue;
                }
                foreach ($matches as $match) {
                    $dependencies[$match] = true;
                }
            }

            $requires[$name] = array_keys($dependencies);
        }

        return new ProviderManifest($metadata)->collect($requires);
    }

    /**
     * @param list<ConstraintInterface> $required
     * @param array<string, list<ConstraintInterface>> $candidates
     * @return list<string>
     */
    private function matchingPackages(array $required, array $candidates): array
    {
        $matches = [];
        foreach ($candidates as $name => $constraints) {
            foreach ($constraints as $constraint) {
                foreach ($required as $requirement) {
                    if ($requirement->matches($constraint)) {
                        $matches[] = $name;
                        continue 3;
                    }
                }
            }
        }
        return $matches;
    }

    /**
     * Composer falls back to root-require reachability when installed.json
     * does not contain a nonempty dev-package-names list. Mirror its
     * AutoloadGenerator::filterPackageMap eligibility rules here, including
     * the distinction between replacement traversal and provided names.
     *
     * @param array<string, PackageInterface> $packages
     * @return array<string, PackageInterface>
     */
    private function filterLegacyProductionPackages(PackageInterface $root, array $packages): array
    {
        $replacedBy = [];
        foreach ($packages as $name => $package) {
            if ($package === $root) {
                continue;
            }
            foreach ($package->getReplaces() as $link) {
                $replacedBy[$link->getTarget()] = $name;
            }
        }

        $included = [];
        $pending = [$root];
        while ($pending !== []) {
            $package = array_pop($pending);
            foreach ($package->getRequires() as $link) {
                $target = $link->getTarget();
                $target = $replacedBy[$target] ?? $target;
                if (isset($included[$target])) {
                    continue;
                }
                $included[$target] = true;
                if (isset($packages[$target]) && $packages[$target] !== $root) {
                    $pending[] = $packages[$target];
                }
            }
        }

        return array_filter($packages, static function (PackageInterface $package) use ($root, $included): bool {
            if ($package === $root) {
                return true;
            }
            foreach ($package->getNames() as $name) {
                if (isset($included[$name])) {
                    return true;
                }
            }
            return false;
        });
    }
}
