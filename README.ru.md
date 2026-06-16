# Componenta Composer Plugin

Composer-плагин, который генерирует список провайдеров конфигурации установленных Componenta-пакетов. Пакеты объявляют провайдеры через metadata Composer, а плагин записывает PHP-файл, который затем подключает конфигурация приложения.

Используйте этот пакет в проектах приложений. Библиотечные пакеты не должны запускать генерацию самостоятельно: они только объявляют свои провайдеры в `extra.componenta.config-providers`.

## Установка

```bash
composer require componenta/composer-plugin
```

Composer должен разрешать выполнение плагина:

```json
{
  "config": {
    "allow-plugins": {
      "componenta/composer-plugin": true
    }
  }
}
```

## Границы пакета

Пакет не загружает конфигурацию приложения и не создаёт контейнер. Он только читает metadata установленных Composer-пакетов и генерирует файл со списком provider-классов.

Загрузка этого файла выполняется приложением, обычно через `Componenta\App\Config\ComposerPackageConfigProvider`.

## Metadata пакета

Пакет объявляет провайдеры так:

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

Корневой проект задаёт путь к сгенерированному файлу:

```json
{
  "extra": {
    "componenta": {
      "config-providers-file": "config/componenta-providers.php"
    }
  }
}
```

Если путь не задан, используется `config/componenta-providers.php`.

## Поведение во время выполнения Composer

Плагин запускается на событиях Composer `post-autoload-dump`, `post-install-cmd` и `post-update-cmd`. Он собирает provider-классы из текущего набора установленных пакетов и атомарно обновляет сгенерированный файл.

Сгенерированный файл возвращает массив имён provider-классов и не предназначен для ручного редактирования.

## Основные классы

- `ComponentaPlugin` — точка входа Composer-плагина.
- `ProviderCollector` — собирает provider-классы из пакетов.
- `ProviderExtractor` — читает `extra.componenta.config-providers`.
- `ProviderFileRenderer` — рендерит PHP-файл с массивом классов.
- `ProviderFileWriter` — записывает файл без перезаписи, если содержимое не изменилось.

## Связанные пакеты

- [`componenta/config`](../config/README.ru.md) определяет базовый класс провайдера и загрузку конфигурации.
- [`componenta/app`](../app/README.ru.md) использует `ComposerPackageConfigProvider` для подключения сгенерированного файла.
- [`componenta/skeleton`](../../componenta-skeleton/README.ru.md) задаёт путь `config/componenta-providers.php` в новом приложении.
