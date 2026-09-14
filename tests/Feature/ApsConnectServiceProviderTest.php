<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Neocode\ApsConnect\ApsConnect;
use Neocode\ApsConnect\Data\AppStationCredentials;
use Neocode\ApsConnect\Data\RegistraCredentials;
use Neocode\ApsConnect\Http\AppStationClient;
use Neocode\ApsConnect\Http\RegistraClient;
use Neocode\ApsConnect\Support\ProjectConfigReader;

beforeEach(function () {
    // The service provider resolves ProjectConfigReader($app->basePath()) —
    // the real Testbench app has no appstation.conf.json there. Rebinding
    // ProjectConfigReader::class itself, rather than overriding
    // RegistraCredentials/AppStationCredentials directly, exercises the exact
    // same production wiring — both credentials resolve through the same
    // cached ProjectConfigReader singleton — without touching the shared
    // Testbench skeleton, which is unsafe to write to under parallel test
    // execution.
    $this->basePath = sys_get_temp_dir().'/aps-connect-tests-'.uniqid();
    mkdir($this->basePath, recursive: true);
    // The base urls have no config/env fallback (see ProjectConfigReaderTest
    // for that coverage), so both must be declared explicitly here.
    file_put_contents($this->basePath.'/appstation.conf.json', json_encode([
        'environment' => 'production',
        'api' => ['baseUrl' => 'https://registra.neocode.ci/api'],
        'appstation' => ['baseUrl' => 'https://app-station.neocode.ci'],
    ]));
    file_put_contents($this->basePath.'/appstation.conf.local.json', json_encode([
        'auth' => ['apiKey' => 'secret-key'],
    ]));

    app()->singleton(ProjectConfigReader::class, fn () => new ProjectConfigReader($this->basePath));
});

afterEach(function () {
    foreach (glob($this->basePath.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->basePath);
});

it('resolves ProjectConfigReader as a singleton', function () {
    expect(app(ProjectConfigReader::class))->toBe(app(ProjectConfigReader::class));
});

it('reuses the same ProjectConfigReader to resolve both Registra and App Station credentials, reading appstation.conf.json only once', function () {
    app(RegistraCredentials::class);

    // Overwrite the project file with a base URL that would surface here if
    // appStationCredentials() re-read it from disk instead of reusing the
    // same ProjectConfigReader's already-cached decode of the file.
    file_put_contents($this->basePath.'/appstation.conf.json', json_encode([
        'environment' => 'production',
        'api' => ['baseUrl' => 'https://changed.example.com/api'],
        'appstation' => ['baseUrl' => 'https://changed.example.com'],
    ]));

    expect(app(AppStationCredentials::class)->baseUrl)->toBe('https://app-station.neocode.ci');
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

it('registers the aps-connect:doctor artisan command', function () {
    expect(Artisan::all())->toHaveKey('aps-connect:doctor');
});
