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
        if (!array_is_list($providers)) {
            throw new \UnexpectedValueException('Expected a list of provider class names.');
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n";

        foreach ($providers as $provider) {
            if (!is_string($provider) || !ProviderClassName::isValid($provider)) {
                throw new \UnexpectedValueException('Invalid Componenta config provider.');
            }

            $contents .= '    ' . var_export($provider, true) . ",\n";
        }

        return $contents . "];\n";
    }
}
