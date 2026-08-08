# Componenta Composer Plugin

Composer plugin that generates the list of Componenta package config providers installed in an application. Packages expose providers through Composer metadata, and this plugin writes a PHP file that the application config can load.

Use this package in application projects. Library packages should only declare their providers in `extra.componenta.config-providers`.

## Installation

```bash
composer require componenta/composer-plugin
```

Composer must allow the plugin:

```json
{
  "config": {
    "allow-plugins": {
      "componenta/composer-plugin": true
    }
  }
}
```

## Package Boundary

The package does not load application configuration and does not build the DI container. It only reads Composer package metadata and generates a PHP file with provider class names.

Applications load that file explicitly, usually through `Componenta\App\Config\ComposerPackageConfigProvider`.

## Package Metadata

A package exposes providers like this:

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

The root project controls the generated file path:

```json
{
  "extra": {
    "componenta": {
      "config-providers-file": "config/componenta-providers.php"
    }
  }
}
```


Provider packages are topologically ordered so dependencies appear before dependants. The collector follows transitive dependencies through packages that expose no provider, which keeps core providers before integration providers such as `componenta/cqrs-app`. Original Composer order is the tie-breaker for unrelated packages.

Only provider packages participate in cycle validation. Third-party dependency cycles that cannot affect provider order are ignored; a cycle between provider packages and duplicate package records are rejected. Repeated provider class names are emitted once at their first ordered position.
If no path is configured, the plugin uses `config/componenta-providers.php`.

## Runtime Behavior

The plugin runs on Composer `post-autoload-dump`, `post-install-cmd`, and `post-update-cmd` events. It collects provider classes from the current installed package set and updates the generated file.

The file is written through a temporary file and `rename()`. Unchanged contents are not rewritten, and `opcache_invalidate()` is called after a successful replacement when OPcache is available.

The generated file returns an array of provider class names and should not be edited manually.

## Public Classes

- `ComponentaPlugin` is the Composer plugin entry point.
- `ProviderCollector` reads installed packages.
- `ProviderExtractor` reads provider metadata from package `extra`.
- `ProviderFileRenderer` renders the PHP array.
- `ProviderFileWriter` writes the generated file.

## Related Packages

- [`componenta/config`](../config/README.md) loads config providers.
- [`componenta/skeleton`](../../componenta-skeleton/README.md) configures the generated provider file in a new application.
