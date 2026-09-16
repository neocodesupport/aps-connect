<?php

declare(strict_types=1);

use Neocode\ApsConnect\Support\EnvironmentFileInstaller;

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/aps-connect-env-installer-tests-'.uniqid();
    mkdir($this->basePath, recursive: true);

    $this->installer = new EnvironmentFileInstaller($this->basePath);
});

afterEach(function () {
    $remove = function (string $dir) use (&$remove): void {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            is_dir($path) ? $remove($path) : unlink($path);
        }

        rmdir($dir);
    };

    $remove($this->basePath);
});

it('reports envExists() correctly', function () {
    expect($this->installer->envExists())->toBeFalse();

    file_put_contents($this->basePath.'/.env', 'APP_NAME=Test');

    expect($this->installer->envExists())->toBeTrue();
});

it('copies .env.example verbatim when the project ships one', function () {
    file_put_contents($this->basePath.'/.env.example', "APP_NAME=Example\nMAIL_MAILER=smtp\n");

    $this->installer->createFromExample();

    expect(file_get_contents($this->basePath.'/.env'))->toBe("APP_NAME=Example\nMAIL_MAILER=smtp\n");
});

it('falls back to a minimal template when there is no .env.example', function () {
    $this->installer->createFromExample();

    $contents = file_get_contents($this->basePath.'/.env');

    expect($contents)->toContain('APP_KEY=')
        ->toContain('DB_CONNECTION=sqlite')
        ->toContain('APP_ENV=production');
});

it('replaces an existing key in place', function () {
    file_put_contents($this->basePath.'/.env', "APP_NAME=Example\nAPP_URL=http://localhost\n");

    $this->installer->setValues(['APP_URL' => 'https://example.test']);

    expect(file_get_contents($this->basePath.'/.env'))->toBe("APP_NAME=Example\nAPP_URL=https://example.test\n");
});

it('uncomments and sets an already-commented-out key', function () {
    file_put_contents($this->basePath.'/.env', "APP_NAME=Example\n# DB_HOST=\n");

    $this->installer->setValues(['DB_HOST' => '127.0.0.1']);

    expect(file_get_contents($this->basePath.'/.env'))->toBe("APP_NAME=Example\nDB_HOST=127.0.0.1\n");
});

it('appends a key that is not declared at all yet', function () {
    file_put_contents($this->basePath.'/.env', "APP_NAME=Example\n");

    $this->installer->setValues(['DB_DATABASE' => 'my_app']);

    expect(file_get_contents($this->basePath.'/.env'))->toBe("APP_NAME=Example\nDB_DATABASE=my_app\n");
});

it('quotes a value containing whitespace', function () {
    file_put_contents($this->basePath.'/.env', "APP_NAME=Example\n");

    $this->installer->setValues(['APP_NAME' => 'My Software']);

    expect(file_get_contents($this->basePath.'/.env'))->toBe("APP_NAME=\"My Software\"\n");
});

it('creates the sqlite database file only when missing', function () {
    $this->installer->ensureSqliteFileExists();

    $path = $this->basePath.'/database/database.sqlite';

    expect(is_file($path))->toBeTrue();

    file_put_contents($path, 'existing-bytes');

    $this->installer->ensureSqliteFileExists();

    expect(file_get_contents($path))->toBe('existing-bytes');
});
