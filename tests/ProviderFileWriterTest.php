<?php

declare(strict_types=1);

use Componenta\ComposerPlugin\ProviderFileWriter;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . '/componenta-writer-' . bin2hex(random_bytes(8));
    mkdir($this->directory, 0700);
});

afterEach(function (): void {
    foreach (scandir($this->directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path) && !is_link($path)) {
            rmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($this->directory);
});

it('preserves existing permission bits when replacing a provider file', function (): void {
    $file = $this->directory . '/providers.php';
    file_put_contents($file, '<?php return [];');
    chmod($file, 0640);

    expect((new ProviderFileWriter())->write($file, ['Example\\Provider']))->toBeTrue();
    clearstatcache(true, $file);
    expect(fileperms($file) & 0777)->toBe(0640)
        ->and(require $file)->toBe(['Example\\Provider']);
})->skip(PHP_OS_FAMILY === 'Windows', 'POSIX permission bits');

it('uses the process umask for a newly generated file', function (): void {
    $file = $this->directory . '/providers.php';
    $old = umask(0027);
    try {
        expect((new ProviderFileWriter())->write($file, []))->toBeTrue();
        clearstatcache(true, $file);
        expect(fileperms($file) & 0777)->toBe(0640);
    } finally {
        umask($old);
    }
})->skip(PHP_OS_FAMILY === 'Windows', 'POSIX permission bits');

it('does not replace symbolic links or modify their targets', function (): void {
    $target = $this->directory . '/target.php';
    $file = $this->directory . '/providers.php';
    file_put_contents($target, 'original');
    symlink($target, $file);

    expect(fn() => (new ProviderFileWriter())->write($file, []))->toThrow(RuntimeException::class);
    expect(is_link($file))->toBeTrue()
        ->and(file_get_contents($target))->toBe('original');
})->skip(PHP_OS_FAMILY === 'Windows', 'Symlink privileges vary on Windows');

it('rejects a directory as the output file', function (): void {
    $file = $this->directory . '/providers.php';
    mkdir($file);
    expect(fn() => (new ProviderFileWriter())->write($file, []))->toThrow(RuntimeException::class);
    expect(is_dir($file))->toBeTrue();
});

it('does not touch an existing file when provider validation fails', function (): void {
    $file = $this->directory . '/providers.php';
    file_put_contents($file, 'original');

    expect(fn() => (new ProviderFileWriter())->write($file, ['invalid name']))
        ->toThrow(UnexpectedValueException::class);
    expect(file_get_contents($file))->toBe('original')
        ->and(scandir($this->directory))->toBe(['.', '..', 'providers.php']);
});

it('rejects stream wrappers before any file operation', function (): void {
    expect(fn() => (new ProviderFileWriter())->write('php://memory', []))
        ->toThrow(RuntimeException::class, 'local filesystem path');
});

it('preserves the previous file when its directory cannot accept a temporary file', function (): void {
    $file = $this->directory . '/providers.php';
    file_put_contents($file, 'original');
    chmod($this->directory, 0500);

    try {
        clearstatcache(true, $this->directory);
        if (is_writable($this->directory)) {
            $this->markTestSkipped('The effective user can write despite the directory permissions.');
        }
        expect(fn() => (new ProviderFileWriter())->write($file, ['Example\\Provider']))
            ->toThrow(RuntimeException::class, 'Unable to write to directory');
        expect(file_get_contents($file))->toBe('original')
            ->and(scandir($this->directory))->toBe(['.', '..', 'providers.php']);
    } finally {
        chmod($this->directory, 0700);
    }
})->skip(PHP_OS_FAMILY === 'Windows', 'POSIX permission bits');

it('rejects a dangling symbolic link without creating its target', function (): void {
    $target = $this->directory . '/missing.php';
    $file = $this->directory . '/providers.php';
    symlink($target, $file);

    expect(fn() => (new ProviderFileWriter())->write($file, []))->toThrow(RuntimeException::class);
    expect(is_link($file))->toBeTrue()
        ->and(file_exists($target))->toBeFalse();
})->skip(PHP_OS_FAMILY === 'Windows', 'Symlink privileges vary on Windows');
