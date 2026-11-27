<?php

declare(strict_types=1);

use Componenta\ComposerPlugin\InstalledMetadataReader;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . '/componenta-installed-metadata-' . bin2hex(random_bytes(8));
    mkdir($this->directory, 0700);
    $this->file = $this->directory . '/installed.php';
    $this->marker = $this->directory . '/executed';
});

afterEach(function (): void {
    foreach ([$this->file, $this->marker] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($this->directory);
});

it('reads the literal data emitted by Composer without executing it', function (): void {
    $records = [
        'example/package' => [
            'version' => 'dev-main',
            'reference' => "hash\\with'quote\0suffix",
            'aliases' => ['2.0.x-dev', '3.0.x-dev'],
            'dev_requirement' => false,
            'install_path' => '/absolute/path',
        ],
    ];
    file_put_contents($this->file, '<?php return ' . var_export(['versions' => $records], true) . ';');

    expect((new InstalledMetadataReader())->versions($this->file))->toBe($records);
});

it('handles Composer relative install paths, comments and empty arrays', function (): void {
    file_put_contents($this->file, <<<'CODE'
<?php
return array(
    'versions' => array(
        'example/package' => array(
            'version' => '1.0.0.0',
            'reference' => null,
            'aliases' => array(),
            'install_path' => __DIR__ . '/../example/package',
            'dev_requirement' => true,
        ),
    ),
);
CODE);

    expect((new InstalledMetadataReader())->versions($this->file))->toBe([
        'example/package' => [
            'version' => '1.0.0.0',
            'reference' => null,
            'aliases' => [],
            'install_path' => realpath($this->directory) . '/../example/package',
            'dev_requirement' => true,
        ],
    ]);
});

it('accepts literal short arrays and missing metadata', function (): void {
    $reader = new InstalledMetadataReader();
    expect($reader->versions($this->file))->toBe([]);
    file_put_contents($this->file, "<?php return ['versions' => ['example/package' => ['aliases' => ['2.0.x-dev']]]];");
    expect($reader->versions($this->file))->toBe(['example/package' => ['aliases' => ['2.0.x-dev']]]);
});

it('rejects executable metadata without running it', function (): void {
    $call = 'file_put_contents(' . var_export($this->marker, true) . ", 'executed')";
    $reader = new InstalledMetadataReader();

    foreach ([
        '<?php ' . $call . '; return array(\'versions\' => array());',
        '<?php return array(\'versions\' => array(\'x\' => ' . $call . '));',
        '<?php return array(\'versions\' => array()); ' . $call . ';',
        '<?php return array(\'versions\' => array(\'x\' => \'prefix\' . ' . $call . '));',
        '<?php return array(\'versions\' => array(\'x\' => $_SERVER));',
        '<?php return array(\'versions\' => array(\'x\' => "$HOME"));',
        '<?php return array(\'versions\' => array(\'x\' => new stdClass()));',
        '<?php return array(\'versions\' => array(\'x\' => include \'file.php\'));',
    ] as $source) {
        file_put_contents($this->file, $source);
        expect(fn() => $reader->versions($this->file))->toThrow(UnexpectedValueException::class);
        expect(file_exists($this->marker))->toBeFalse();
    }
});

it('rejects malformed and ambiguous metadata arrays', function (string $source): void {
    file_put_contents($this->file, $source);
    expect(fn() => (new InstalledMetadataReader())->versions($this->file))->toThrow(UnexpectedValueException::class);
})->with([
    "<?php return array('versions' => null);",
    "<?php return ['versions' => ['example/package' => false]];",
    "<?php return ['versions' => [['version' => '1.0.0.0']]];",
    "<?php return ['versions' => ['' => []]];",
    "<?php return ['versions' => ['example/package' => [" . PHP_INT_MAX . " => 1, 2]]];",
    "<?php return array('versions' => array(), 'versions' => array());",
    "<?php return array('versions' => array()); ?>text",
    "<?php return array('versions' => array('x' => 'unterminated));",
    "<?php return array('versions' => array('x' => array(...array())));",
]);

it('roundtrips every byte through Composer-style exported string literals', function (): void {
    $reader = new InstalledMetadataReader();
    for ($byte = 0; $byte < 256; ++$byte) {
        $records = ['example/package' => ['reference' => "a" . chr($byte) . "b\\'"]];
        file_put_contents($this->file, '<?php return ' . var_export(['versions' => $records], true) . ';');
        expect($reader->versions($this->file))->toBe($records);
    }
});

it('distinguishes a missing metadata file from an existing directory', function (): void {
    $reader = new InstalledMetadataReader();
    expect($reader->versions($this->file))->toBe([]);

    mkdir($this->file);
    try {
        expect(fn() => $reader->versions($this->file))->toThrow(UnexpectedValueException::class);
    } finally {
        rmdir($this->file);
    }
});

it('rejects a dangling metadata symlink while allowing a valid file symlink', function (): void {
    $reader = new InstalledMetadataReader();
    $target = $this->directory . '/target.php';
    symlink($target, $this->file);

    try {
        expect(fn() => $reader->versions($this->file))->toThrow(UnexpectedValueException::class);
        file_put_contents($target, "<?php return ['versions' => ['example/package' => ['aliases' => []]]];");
        expect($reader->versions($this->file))->toBe(['example/package' => ['aliases' => []]]);
    } finally {
        unlink($this->file);
        if (is_file($target)) {
            unlink($target);
        }
    }
})->skip(PHP_OS_FAMILY === 'Windows', 'Symlink privileges vary on Windows');
