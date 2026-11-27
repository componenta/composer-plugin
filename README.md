# Componenta Composer Plugin

Generates a PHP file containing the ordered list of configuration provider class names declared by Composer packages. PHP 8.4+, the tokenizer extension and Composer Plugin API 2.6+ are required.

## Installation

Install the plugin as a production requirement of the application, not as a development-only dependency or in reusable libraries:

```bash
composer require componenta/composer-plugin
```

Authorize the plugin in the root project's Composer configuration:

```json
{
  "config": {
    "allow-plugins": {
      "componenta/composer-plugin": true
    }
  }
}
```

## Package metadata

Packages declare their providers in their `composer.json`:

```json
{
  "extra": {
    "componenta": {
      "config-providers": [
        "Componenta\\Http\\Router\\ConfigProvider"
      ]
    }
  }
}
```

The root package may declare providers in the same way. Omit the key if there are no providers, or use an empty list. If present, `extra.componenta` must be an object and `config-providers` a list of nonempty class names. Null, malformed identifiers and reserved class names are rejected. Names use namespace separators without a leading separator. Classes are not autoloaded or instantiated by the plugin.

Only the root project controls the output file:

```json
{
  "extra": {
    "componenta": {
      "config-providers-file": "config/componenta-providers.php"
    }
  }
}
```

The default is `config/componenta-providers.php`. Relative paths are resolved against the project directory (the logical location of the CLI composer.json, even when it is a symlink); absolute local paths are supported. An explicitly invalid or null path is rejected. A globally installed plugin does not generate a file in unrelated projects unless the project explicitly configures the path or requires/installs the plugin locally.

## Ordering and generated file

Provider packages follow their installed dependencies, including paths through non-provider packages. Version aliases are considered when resolving requirements, including alternative self.version requirements rewritten by an alias. Missing inline aliases are restored from the project's generated installed.php metadata, only for installed packages with matching versions and references; the lock file is not used to expand the installed set. If reinstall drops inline aliases from the generated metadata, the current root project's inline declarations supplement the known aliases only for an installed package with the exact declared base version. This fallback interprets the current root configuration; it never introduces a package outside the installed set. The generated installed.php is parsed as literal array data, including Composer's relative path expressions; it is never executed. Invalid or executable metadata aborts generation without replacing the previous manifest. An installed matching package with the exact required name takes precedence over virtual providers and replacers. If no direct match exists, all installed compatible providers/replacers are considered; Composer does not record a unique chosen implementation for ambiguous virtual requirements. A self-satisfied requirement adds no edge. Composer platform requirements (PHP, extensions, system libraries and Composer APIs) do not create provider-ordering edges, even when installed polyfills advertise them. They can be satisfied by the platform itself, and the resulting provider order must not depend on the PHP environment running Composer. Declare a dependency on the actual package if its configuration provider is required.

Among provider packages whose provider dependencies have already been emitted, the root project has priority, followed by installed package names in ascending lexical order. This makes output independent of installation order and installed.json serialization. The standalone ProviderPackageSorter retains input order as its tie-breaker. A cycle between multiple provider packages is rejected. A cycle involving only non-provider packages or a single provider does not by itself make provider ordering impossible. Duplicate package records in the provider sorter are rejected. Repeated provider names are emitted once, comparing ASCII case and preserving the first spelling. The order of classes within a package is preserved.

The generated file returns an ordered list of strings:

```php
<?php

declare(strict_types=1);

return [
    'Componenta\\App\\ConfigProvider',
    'Componenta\\Router\\ConfigProvider',
];
```

Application code consumes the returned list; in Componenta App this is the responsibility of `Componenta\App\Config\ComposerPackageConfigProvider`. The plugin does not load configuration, create a container or build the application.

## Composer lifecycle

Generation runs on `post-autoload-dump`, `post-install-cmd` and `post-update-cmd`. It uses installed packages rather than the lock file alone, and respects the event's dev mode. With `--no-dev`, installed dev-only packages are excluded in accordance with Composer's dev-package metadata (or Composer's legacy reachability rules when that metadata is empty). Root require-dev links affect ordering only in dev mode.

Both `composer dump-autoload --no-dev` and `composer dump-autoload --dev` refresh the list. Installation or update with `--no-autoloader` still refreshes the list; the application must have a matching autoloader before booting. Lock-only updates and download-only installs leave the existing manifest intact. Commands that disable plugins or scripts may skip generation. Regenerate using `composer dump-autoload` with plugins and scripts enabled before running the application.

Unchanged content is not rewritten. Changed content is written completely to a temporary file in the destination directory and replaced with a rename. Existing permission bits are retained; a new file uses 0666 masked by the process umask. Ownership and extended ACLs are not promised. Symbolic links and non-regular destination files are rejected. OPcache invalidation is attempted only for the current PHP environment; worker processes may need restarting.

The file is a generated build artifact. Exclude it from version control and ensure it is generated before application bootstrap. Do not edit it manually. Deactivation and uninstallation do not remove it automatically, because the target is configured by the application and may be user-owned. Remove the obsolete file explicitly when removing the integration. Generation errors fail the Composer command; a validated previous file is not intentionally overwritten on a failed temporary write.

## Public API

- `ComponentaPlugin`: Composer plugin and event subscriber. Manual calls to `generateProviders()` without an event use the installed set.
- `ProviderPackage`: immutable package name and extra metadata.
- `ProviderExtractor::extract()`: validates and returns the declared provider names.
- `ProviderCollector::collect()`: collects provider names in input order and deduplicates them.
- `ProviderPackageSorter::sort()`: orders provider packages using explicit package-name dependencies.
- `ProviderFileRenderer::render()`: validates and renders a list of provider names as PHP.
- `ProviderFileWriter::write()`: updates the target and returns whether its contents changed.

The remaining classes are internal implementation details.

## Development

```bash
composer install
composer test
composer test:integration
```

The integration tests execute the Composer console application through an isolated test entry point that excludes the development package's own classes from its autoloader. They use temporary projects with local path repositories, exercising installation, autoload mode changes, migration from v1.0.3, plugin update and installation without regenerating the autoloader. It does not require external package downloads after development dependencies have been installed.

Related packages: [Componenta App](https://github.com/componenta/app), [Componenta Config](https://github.com/componenta/config), [Componenta Skeleton](https://github.com/componenta/skeleton).
