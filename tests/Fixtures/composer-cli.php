<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Composer\Console\Application;
use Composer\Util\ErrorHandler;
use Composer\Util\Platform;

// Composer installed as a dev dependency shares the tested package's autoloader.
// Only the project's installed plugin may supply its classes in this process,
// including when the outer autoloader has an optimized class map.
$loader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!$loader instanceof ClassLoader) {
    throw new RuntimeException('Expected the development Composer autoloader.');
}
$loader->unregister();
spl_autoload_register(static function (string $class) use ($loader): void {
    if (!str_starts_with(strtolower($class), 'componenta\\composerplugin\\')) {
        $loader->loadClass($class);
    }
});

if (class_exists('Componenta\\ComposerPlugin\\ComponentaPlugin')) {
    throw new RuntimeException('The integration harness must not preload the plugin.');
}

setlocale(LC_ALL, 'C');
Platform::putEnv('COMPOSER_BINARY', __FILE__);
ErrorHandler::register();
(new Application())->run();
