<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

final readonly class ProviderPackage
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $name,
        public array $extra,
    ) {}
}
