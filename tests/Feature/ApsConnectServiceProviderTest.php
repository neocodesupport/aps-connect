<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Neocode\ApsConnect\ApsConnect;
use Neocode\ApsConnect\ApsConnectServiceProvider;
use Neocode\ApsConnect\Data\AppStationCredentials;
use Neocode\ApsConnect\Data\RegistraCredentials;
use Neocode\ApsConnect\Http\AppStationClient;
use Neocode\ApsConnect\Http\RegistraClient;
use Neocode\ApsConnect\Support\ProjectConfigReader;

beforeEach(function () {
    // The service provider resolves RegistraCredentials via
    // ProjectConfigReader($app->basePath()) — the real Testbench app has no
    // appstation.conf.json there. Rebinding to an isolated temp directory
    // exercises the exact same wiring (a singleton factory backed by
    // ProjectConfigReader) without touching the shared Testbench skeleton,
    // which is unsafe to write to under parallel test execution.
    $this->basePath = sys_get_temp_dir().'/aps-connect-tests-'.uniqid();
    mkdir($this->basePath, recursive: true);
    // No explicit api.baseUrl/appstation.baseUrl here: both fall back to
    // config('aps-connect.registra_base_url') / ::appstation_base_url — see
    // ProjectConfigReaderTest for the file-vs-config precedence coverage.
    file_put_contents($this->basePath.'/appstation.conf.json', json_encode([
        'environment' => 'production',
    ]));
    file_put_contents($this->basePath.'/appstation.conf.local.json', json_encode([
        'auth' => ['apiKey' => 'secret-key'],
    ]));

    app()->singleton(RegistraCredentials::class, fn () => (new ProjectConfigReader($this->basePath))->credentials());
    app()->singleton(AppStationCredentials::class, fn () => (new ProjectConfigReader($this->basePath))->appStationCredentials(app(RegistraCredentials::class)));
});

afterEach(function () {
    foreach (glob($this->basePath.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->basePath);
});

it('merges the package config', function () {
    expect(config('aps-connect'))->toHaveKeys(['api_key', 'dev_api_key', 'registra_base_url', 'appstation_base_url']);
});

it('resolves RegistraCredentials as a singleton', function () {
    expect(app(RegistraCredentials::class))->toBe(app(RegistraCredentials::class));
});

it('resolves RegistraClient as a singleton', function () {
    expect(app(RegistraClient::class))->toBe(app(RegistraClient::class));
});

it('resolves AppStationCredentials as a singleton', function () {
    expect(app(AppStationCredentials::class))->toBe(app(AppStationCredentials::class));
});

it('resolves AppStationClient as a singleton', function () {
    expect(app(AppStationClient::class))->toBe(app(AppStationClient::class));
});

it('resolves ApsConnect as a singleton', function () {
    expect(app(ApsConnect::class))->toBe(app(ApsConnect::class));
});

it('publishes the config file under the aps-connect-config tag', function () {
    $paths = ServiceProvider::pathsToPublish(ApsConnectServiceProvider::class, 'aps-connect-config');

    expect($paths)->toHaveCount(1);
    expect(array_values($paths))->toContain(config_path('aps-connect.php'));
});

it('registers the aps-connect:doctor artisan command', function () {
    expect(Artisan::all())->toHaveKey('aps-connect:doctor');
});
