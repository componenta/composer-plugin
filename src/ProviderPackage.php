<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

final readonly class ProviderPackage
{
    /**
     * @param array<string, mixed> $extra
     * @param list<string> $requires
     */
    public function __construct(
        public string $name,
        public array $extra,
        public array $requires = [],
    ) {}
}
