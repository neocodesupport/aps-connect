<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Neocode\ApsConnect\Support\ProjectInspector;

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/aps-connect-inspector-'.bin2hex(random_bytes(8));
    mkdir($this->basePath, recursive: true);
    $this->inspector = new ProjectInspector($this->basePath);
});

afterEach(function () {
    foreach (glob($this->basePath.'/*') ?: [] as $file) {
        unlink($file);
    }

    @rmdir($this->basePath);
});

it('fingerprints the repository by its origin remote url', function () {
    Process::fake(['*' => Process::result("git@github.com:acme/my-app.git\n")]);

    expect($this->inspector->fingerprint())->toBe(hash('sha256', 'git@github.com:acme/my-app.git'));
});

it('falls back to the local path when there is no origin remote', function () {
    Process::fake(['*' => Process::result(errorOutput: 'error: No such remote', exitCode: 2)]);

    expect($this->inspector->fingerprint())->toBe(hash('sha256', 'local:'.realpath($this->basePath)));
});

it('detects the stack like aps-cli, package.json first', function (array $files, ?string $stack) {
    foreach ($files as $name => $contents) {
        file_put_contents($this->basePath.'/'.$name, $contents);
    }

    expect($this->inspector->stack())->toBe($stack);
})->with([
    'nothing' => [[], null],
    'php' => [['composer.json' => '{}'], 'php'],
    'node wins over php' => [['composer.json' => '{}', 'package.json' => '{"devDependencies": {"vite": "^7"}}'], 'node'],
    'vue' => [['package.json' => '{"dependencies": {"vue": "^3"}}'], 'vue'],
    'electron before react' => [['package.json' => '{"dependencies": {"react": "^19", "electron": "^38"}}'], 'electron'],
    'flutter' => [['pubspec.yaml' => ''], 'flutter'],
    'dotnet' => [['Directory.Build.props' => ''], 'dotnet'],
]);

it('names the project after package.json, else the directory', function () {
    expect($this->inspector->name())->toBe(basename($this->basePath));

    file_put_contents($this->basePath.'/package.json', '{"name": "my-app"}');

    expect($this->inspector->name())->toBe('my-app');
});
