<?php

declare(strict_types=1);

use Componenta\ComposerPlugin\ProviderManifest;
use Componenta\ComposerPlugin\ProviderPackage;
use Componenta\ComposerPlugin\ProviderPackageSorter;

function orderingPackage(string $name, string $provider): ProviderPackage
{
    return new ProviderPackage($name, ['componenta' => ['config-providers' => [$provider]]]);
}

it('uses original input order among packages whose provider dependencies are satisfied', function (): void {
    $packages = [
        orderingPackage('example/feature', 'Example\\Feature'),
        orderingPackage('example/unrelated', 'Example\\Unrelated'),
        orderingPackage('example/dependency', 'Example\\Dependency'),
    ];
    $requires = ['example/feature' => ['example/dependency']];

    expect((new ProviderManifest($packages))->collect($requires))
        ->toBe(['Example\\Unrelated', 'Example\\Dependency', 'Example\\Feature']);
    expect((new ProviderPackageSorter())->sort($packages, $requires))
        ->toBe([$packages[1], $packages[2], $packages[0]]);
});

it('handles cycles of non-provider packages between providers', function (): void {
    $packages = [
        orderingPackage('example/feature', 'Example\\Feature'),
        new ProviderPackage('example/bridge-a', []),
        new ProviderPackage('example/bridge-b', []),
        orderingPackage('example/dependency', 'Example\\Dependency'),
    ];
    $requires = [
        'example/feature' => ['example/bridge-a'],
        'example/bridge-a' => ['example/bridge-b'],
        'example/bridge-b' => ['example/bridge-a', 'example/dependency'],
    ];

    expect((new ProviderManifest($packages))->collect($requires))
        ->toBe(['Example\\Dependency', 'Example\\Feature']);
});

it('rejects cycles between providers even through non-provider packages', function (): void {
    $packages = [
        orderingPackage('example/a', 'Example\\A'),
        orderingPackage('example/b', 'Example\\B'),
        new ProviderPackage('example/bridge', []),
    ];

    expect(fn() => (new ProviderManifest($packages))->collect([
        'example/a' => ['example/bridge'],
        'example/bridge' => ['example/b'],
        'example/b' => ['example/a'],
    ]))->toThrow(UnexpectedValueException::class, 'Circular Composer dependency');
});

it('ignores a dependency cycle containing just one provider', function (): void {
    $packages = [orderingPackage('example/a', 'Example\\A'), new ProviderPackage('example/bridge', [])];

    expect((new ProviderManifest($packages))->collect([
        'example/a' => ['example/bridge'],
        'example/bridge' => ['example/a'],
    ]))->toBe(['Example\\A']);
});

it('rejects duplicate package records rather than silently replacing them', function (): void {
    expect(fn() => (new ProviderPackageSorter())->sort([
        new ProviderPackage('example/a', []),
        new ProviderPackage('example/a', []),
    ], []))->toThrow(UnexpectedValueException::class, 'Duplicate Composer package');
});

it('handles a long dependency chain without recursive traversal', function (): void {
    $packages = [];
    $requires = [];
    for ($i = 0; $i < 1000; $i++) {
        $packages[] = orderingPackage('example/p' . $i, 'Example\\P' . $i);
        if ($i < 999) {
            $requires['example/p' . $i] = ['example/p' . ($i + 1)];
        }
    }

    $expected = array_map(static fn(int $i): string => 'Example\\P' . $i, range(999, 0));
    expect((new ProviderManifest($packages))->collect($requires))->toBe($expected);
});

it('agrees with an independent reachability oracle for every four-package graph and provider subset', function (): void {
    $names = ['example/a', 'example/b', 'example/c', 'example/d'];
    $pairs = [];
    for ($from = 0; $from < 4; ++$from) {
        for ($to = 0; $to < 4; ++$to) {
            if ($from !== $to) {
                $pairs[] = [$from, $to];
            }
        }
    }

    $sorter = new ProviderPackageSorter();
    $collector = new \Componenta\ComposerPlugin\ProviderCollector();
    $checked = 0;

    for ($graph = 0; $graph < (1 << count($pairs)); ++$graph) {
        $requires = [];
        $reach = array_fill(0, 4, 0);
        foreach ($pairs as $index => [$from, $to]) {
            if (($graph & (1 << $index)) !== 0) {
                $requires[$names[$from]][] = $names[$to];
                $reach[$from] |= 1 << $to;
            }
        }

        // Independent transitive closure (Floyd-Warshall), including cycles.
        for ($through = 0; $through < 4; ++$through) {
            for ($from = 0; $from < 4; ++$from) {
                if (($reach[$from] & (1 << $through)) !== 0) {
                    $reach[$from] |= $reach[$through];
                }
            }
        }

        for ($mask = 0; $mask < 16; ++$mask) {
            $packages = [];
            for ($index = 0; $index < 4; ++$index) {
                $packages[] = ($mask & (1 << $index)) !== 0
                    ? orderingPackage($names[$index], 'Example\\P' . $index)
                    : new ProviderPackage($names[$index], []);
            }

            $emitted = 0;
            $expected = [];
            while ($emitted !== $mask) {
                $next = null;
                for ($index = 0; $index < 4; ++$index) {
                    $bit = 1 << $index;
                    if (($mask & $bit) !== 0 && ($emitted & $bit) === 0
                        && ($reach[$index] & $mask & ~$emitted & ~$bit) === 0) {
                        $next = $index;
                        break;
                    }
                }

                if ($next === null) {
                    break;
                }
                $emitted |= 1 << $next;
                $expected[] = 'Example\\P' . $next;
            }

            try {
                $actual = $collector->collect($sorter->sort($packages, $requires));
            } catch (UnexpectedValueException $exception) {
                if ($emitted === $mask) {
                    throw new RuntimeException("False cycle in graph $graph, subset $mask.", 0, $exception);
                }
                ++$checked;
                continue;
            }

            if ($emitted !== $mask || $actual !== $expected) {
                throw new RuntimeException("Incorrect provider order in graph $graph, subset $mask.");
            }
            ++$checked;
        }
    }

    expect($checked)->toBe(65536);
});
