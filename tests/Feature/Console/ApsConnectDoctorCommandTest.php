<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Neocode\ApsConnect\Data\AppStationCredentials;
use Neocode\ApsConnect\Data\RegistraCredentials;
use Neocode\ApsConnect\Support\ProjectConfigReader;

beforeEach(function () {
    // See ApsConnectServiceProviderTest for why this rebinds to an isolated
    // temp directory. No explicit api.baseUrl/appstation.baseUrl here: both
    // fall back to config('aps-connect.registra_base_url') /
    // ::appstation_base_url.
    $this->basePath = sys_get_temp_dir().'/aps-connect-tests-'.uniqid();
    mkdir($this->basePath, recursive: true);
    file_put_contents($this->basePath.'/appstation.conf.json', json_encode([
        'environment' => 'production',
    ]));

    config(['aps-connect.api_key' => 'secret-key']);

    app()->singleton(RegistraCredentials::class, fn () => (new ProjectConfigReader($this->basePath))->credentials());
    app()->singleton(AppStationCredentials::class, fn () => (new ProjectConfigReader($this->basePath))->appStationCredentials(app(RegistraCredentials::class)));
});

afterEach(function () {
    foreach (glob($this->basePath.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->basePath);
});

it('succeeds when the api key is accepted and the probe licence is not found', function () {
    Http::fake([
        '*/software/me' => Http::response(['success' => true, 'data' => [
            'token' => 'tok', 'key' => 'KEY', 'name' => 'My Software', 'slug' => 'my-software',
            'environment' => 'production', 'has_modules' => false, 'has_api_key' => true,
            'api_key_fingerprint' => 'abc', 'linked_production_token' => null,
            'has_previous_api_key_grace_period' => false, 'api_key_previous_expires_at' => null,
        ]]),
        '*/licences/verify' => Http::response(['success' => false, 'message' => 'Licence introuvable.'], 404),
    ]);

    $this->artisan('aps-connect:doctor')
        ->expectsOutputToContain('Connecté à Registra en tant que "My Software"')
        ->expectsOutputToContain('licence de test introuvable')
        ->expectsOutputToContain('App Station : URL résolue sur "'.config('aps-connect.appstation_base_url').'"')
        ->assertSuccessful();
});

it('fails when the api key is rejected while resolving the software identity', function () {
    Http::fake([
        '*/software/me' => Http::response(['success' => false, 'message' => 'Clé invalide.'], 401),
    ]);

    $this->artisan('aps-connect:doctor')
        ->expectsOutputToContain('Registra a rejeté la clé API')
        ->assertFailed();
});

it('fails when the api key is rejected during the verification probe', function () {
    Http::fake([
        '*/software/me' => Http::response(['success' => true, 'data' => [
            'token' => 'tok', 'key' => 'KEY', 'name' => 'My Software', 'slug' => 'my-software',
            'environment' => 'production', 'has_modules' => false, 'has_api_key' => true,
            'api_key_fingerprint' => 'abc', 'linked_production_token' => null,
            'has_previous_api_key_grace_period' => false, 'api_key_previous_expires_at' => null,
        ]]),
        '*/licences/verify' => Http::response(['success' => false, 'message' => 'Clé invalide.'], 401),
    ]);

    $this->artisan('aps-connect:doctor')
        ->expectsOutputToContain('rejetée lors du test de vérification')
        ->assertFailed();
});

it('warns when a previous api key is still valid during its grace period', function () {
    Http::fake([
        '*/software/me' => Http::response(['success' => true, 'data' => [
            'token' => 'tok', 'key' => 'KEY', 'name' => 'My Software', 'slug' => 'my-software',
            'environment' => 'production', 'has_modules' => false, 'has_api_key' => true,
            'api_key_fingerprint' => 'abc', 'linked_production_token' => null,
            'has_previous_api_key_grace_period' => true, 'api_key_previous_expires_at' => '2026-09-01T00:00:00+00:00',
        ]]),
        '*/licences/verify' => Http::response(['success' => false, 'message' => 'Licence introuvable.'], 404),
    ]);

    $this->artisan('aps-connect:doctor')
        ->expectsOutputToContain('ancienne clé API est encore acceptée')
        ->assertSuccessful();
});
