<?php

declare(strict_types=1);

namespace Componenta\ComposerPlugin;

final readonly class ProviderFileRenderer
{
    /**
     * @param list<class-string> $providers
     */
    public function render(array $providers): string
    {
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n";

        foreach ($providers as $provider) {
            $contents .= '    ' . $provider . "::class,\n";
        }

        return $contents . "];\n";
    }
}
