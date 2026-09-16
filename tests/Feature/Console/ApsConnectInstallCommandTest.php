<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Neocode\ApsConnect\Events\ApsConnectInstalled;
use Neocode\ApsConnect\Events\ApsConnectInstalling;
use Neocode\ApsConnect\Support\EnvironmentFileInstaller;

function apsConnectInstallCommandRemoveDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir.'/'.$item;
        is_dir($path) ? apsConnectInstallCommandRemoveDir($path) : unlink($path);
    }

    rmdir($dir);
}

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/aps-connect-install-cmd-tests-'.uniqid();
    mkdir($this->basePath, recursive: true);

    app()->singleton(EnvironmentFileInstaller::class, fn () => new EnvironmentFileInstaller($this->basePath));
});

afterEach(function () {
    apsConnectInstallCommandRemoveDir($this->basePath);
});

it('writes .env on the first run and stops without finishing installation', function () {
    Event::fake();

    $this->artisan('aps-connect:install', ['--app-url' => 'https://example.test', '--db-connection' => 'sqlite'])
        ->expectsOutputToContain('.env écrit')
        ->assertSuccessful();

    expect(is_file($this->basePath.'/.env'))->toBeTrue();
    expect(file_get_contents($this->basePath.'/.env'))->toContain('APP_URL=https://example.test')
        ->toContain('DB_CONNECTION=sqlite');
    expect(is_file($this->basePath.'/database/database.sqlite'))->toBeTrue();

    Event::assertDispatched(ApsConnectInstalling::class);
    Event::assertNotDispatched(ApsConnectInstalled::class);
});

it('prompts for the app url and db connection interactively when neither is given', function () {
    Event::fake();

    $this->artisan('aps-connect:install')
        ->expectsQuestion('APP_URL (ex. https://mon-logiciel.exemple.com)', 'https://interactive.test')
        ->expectsChoice('Connexion base de données', 'sqlite', ['sqlite', 'mysql', 'pgsql'])
        ->assertSuccessful();

    expect(file_get_contents($this->basePath.'/.env'))->toContain('APP_URL=https://interactive.test');
});

it('writes mysql connection details when that driver is chosen', function () {
    Event::fake();

    $this->artisan('aps-connect:install', [
        '--app-url' => 'https://example.test',
        '--db-connection' => 'mysql',
        '--db-host' => 'db.internal',
        '--db-port' => '3307',
        '--db-database' => 'my_app',
        '--db-username' => 'app_user',
        '--db-password' => 'secret',
    ])->assertSuccessful();

    $env = file_get_contents($this->basePath.'/.env');

    expect($env)->toContain('DB_CONNECTION=mysql')
        ->toContain('DB_HOST=db.internal')
        ->toContain('DB_PORT=3307')
        ->toContain('DB_DATABASE=my_app')
        ->toContain('DB_USERNAME=app_user')
        ->toContain('DB_PASSWORD=secret');

    expect(is_file($this->basePath.'/database/database.sqlite'))->toBeFalse();
});

it('defaults to sqlite without prompting when non-interactive and no db-connection is given', function () {
    $this->artisan('aps-connect:install', ['--no-interaction' => true])->assertSuccessful();

    expect(file_get_contents($this->basePath.'/.env'))->toContain('DB_CONNECTION=sqlite');
});

it('finishes installation on the second run once .env already exists', function () {
    Event::fake();

    file_put_contents($this->basePath.'/.env', "APP_NAME=Test\nAPP_KEY=base64:".base64_encode('x'.str_repeat('y', 31))."\n");

    $this->artisan('aps-connect:install')
        ->expectsOutputToContain('Installation terminée')
        ->assertSuccessful();

    Event::assertDispatched(ApsConnectInstalling::class);
    Event::assertDispatched(ApsConnectInstalled::class);
});

it('skips migration when --no-migrate is passed', function () {
    Event::fake();

    file_put_contents($this->basePath.'/.env', "APP_NAME=Test\nAPP_KEY=base64:".base64_encode('x'.str_repeat('y', 31))."\n");

    $this->artisan('aps-connect:install', ['--no-migrate' => true])
        ->doesntExpectOutputToContain('Migration de la base de données')
        ->assertSuccessful();
});
