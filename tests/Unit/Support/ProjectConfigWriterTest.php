<?php

declare(strict_types=1);

use Neocode\ApsConnect\Support\ProjectConfigWriter;

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/aps-connect-config-writer-'.bin2hex(random_bytes(8));
    mkdir($this->basePath, recursive: true);
    $this->writer = new ProjectConfigWriter($this->basePath);
});

afterEach(function () {
    foreach (glob($this->basePath.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
        is_file($file) && unlink($file);
    }

    @rmdir($this->basePath);
});

it('writes JSON the way aps-cli does: 2-space indent, unescaped slashes, trailing newline', function () {
    $this->writer->writeProjectConfig(['api' => ['baseUrl' => 'https://registra.test/api'], 'integration' => ['detectedStack' => null]]);

    expect(file_get_contents($this->basePath.'/appstation.conf.json'))->toBe(<<<'JSON'
        {
          "api": {
            "baseUrl": "https://registra.test/api"
          },
          "integration": {
            "detectedStack": null
          }
        }

        JSON);
});

it('writes the local config for one environment', function () {
    $this->writer->writeLocalConfig('production', 'a-key', 'a-secret');

    expect(json_decode((string) file_get_contents($this->basePath.'/appstation.conf.local.json'), true))->toBe([
        'environment' => 'production',
        'auth' => ['apiKey' => 'a-key'],
        'signingSecret' => 'a-secret',
    ]);
});

it('creates a .gitignore with the secret files', function () {
    expect($this->writer->ensureGitignorePatched())->toBeTrue()
        ->and(file_get_contents($this->basePath.'/.gitignore'))->toBe(
            "# App Station / Registra (genere par aps init)\nappstation.conf.local.json\n.env.registra\nappstation.manifest.signed.json\n",
        );
});

it('appends only the missing patterns to an existing .gitignore', function () {
    file_put_contents($this->basePath.'/.gitignore', "/vendor\nappstation.conf.local.json");

    expect($this->writer->ensureGitignorePatched())->toBeTrue()
        ->and(file_get_contents($this->basePath.'/.gitignore'))->toBe(
            "/vendor\nappstation.conf.local.json\n\n# App Station / Registra (genere par aps init)\n.env.registra\nappstation.manifest.signed.json\n",
        );
});

it('leaves a .gitignore that already lists every pattern untouched', function () {
    $this->writer->ensureGitignorePatched();

    expect($this->writer->ensureGitignorePatched())->toBeFalse();
});
