<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Neocode\ApsConnect\Support\ApsCliSession;
use Neocode\ApsConnect\Support\ProjectConfigReader;

function apsConnectStatusModuleConfig(): array
{
    return [
        'version' => 1,
        'type' => 'module',
        'environment' => 'development',
        'module' => ['token' => 'mod-token', 'slug' => 'invoices', 'name' => 'Invoices'],
        'parentSoftware' => ['token' => 'sw-token', 'name' => 'My App'],
        'api' => ['baseUrl' => 'https://registra-dev.test/api'],
        'appstation' => ['baseUrl' => 'https://app-station.test', 'packageId' => 12, 'parentSoftwareId' => 7, 'projectFingerprint' => 'abcdef0123456789'],
    ];
}

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/aps-connect-status-tests-'.bin2hex(random_bytes(8));
    mkdir($this->basePath, recursive: true);

    file_put_contents($this->basePath.'/appstation.conf.json', json_encode([
        'version' => 1,
        'type' => 'software',
        'environment' => 'development',
        'software' => ['token' => 'sw-token', 'name' => 'My App'],
        'api' => ['baseUrl' => 'https://registra-dev.test/api'],
        'appstation' => ['baseUrl' => 'https://app-station.test', 'softwareId' => 7, 'projectFingerprint' => 'abcdef0123456789'],
    ]));
    file_put_contents($this->basePath.'/appstation.conf.local.json', json_encode([
        'environment' => 'development', 'auth' => ['apiKey' => 'secret-key'], 'signingSecret' => 'secret-signing',
    ]));

    $this->sessionPath = $this->basePath.'/aps-cli-credentials.json';
    file_put_contents($this->sessionPath, json_encode([
        'appstation' => ['baseUrl' => 'https://app-station.test', 'accessToken' => 'session-token'],
    ]));

    app()->singleton(ApsCliSession::class, fn () => new ApsCliSession($this->sessionPath));
    app()->singleton(ProjectConfigReader::class, fn () => new ProjectConfigReader($this->basePath));

    putenv('APS_TOKEN');
    putenv('APPSTATION_BASE_URL');
    putenv('APS_SIGNING_SECRET');
});

afterEach(function () {
    putenv('APS_SIGNING_SECRET');

    foreach (glob($this->basePath.'/*') ?: [] as $file) {
        unlink($file);
    }

    @rmdir($this->basePath);
});

it('shows the local link without any network call', function () {
    Http::fake();

    $this->artisan('aps-connect:status')
        ->expectsOutputToContain('https://app-station.test')
        ->expectsOutputToContain('My App (id 7)')
        ->expectsOutputToContain('https://registra-dev.test/api')
        ->expectsOutputToContain('abcdef012345…')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('shows a module and its parent', function () {
    file_put_contents($this->basePath.'/appstation.conf.json', json_encode(apsConnectStatusModuleConfig()));

    $this->artisan('aps-connect:status')
        ->expectsOutputToContain('Invoices (invoices, id 12)')
        ->expectsOutputToContain('My App (id 7)')
        ->assertSuccessful();
});

it('says when the project is not linked and when there is no session', function () {
    unlink($this->basePath.'/appstation.conf.json');
    unlink($this->sessionPath);

    $this->artisan('aps-connect:status')
        ->expectsOutputToContain('non connecté')
        ->expectsOutputToContain('non lié')
        ->assertSuccessful();
});

it('adds the App Station status and release count with --remote', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares?page=1' => Http::response(['data' => [
            ['id' => 7, 'name' => 'My App', 'slug' => 'my-app', 'status' => 'pending_review'],
        ]]),
        'app-station.test/api/v1/publisher/softwares/7/releases?page=1' => Http::response(['data' => [
            ['id' => 1, 'version' => '1.0.0', 'is_yanked' => false],
            ['id' => 2, 'version' => '1.0.1', 'is_yanked' => true],
        ]]),
    ]);

    $this->artisan('aps-connect:status', ['--remote' => true])
        ->expectsOutputToContain('pending_review')
        ->expectsOutputToContain('Release(s) publiée(s) : 1 (2 au total, yanked incluses).')
        ->assertSuccessful();
});

it('fails --remote when the linked software is not on the account', function () {
    Http::fake(['app-station.test/api/v1/developer/softwares?page=1' => Http::response(['data' => []])]);

    $this->artisan('aps-connect:status', ['--remote' => true])
        ->expectsOutputToContain('Software introuvable sur votre compte App Station.')
        ->assertFailed();
});

it('shows where secrets come from, never their values, with --secrets', function () {
    putenv('APS_SIGNING_SECRET=from-env');

    $this->artisan('aps-connect:status', ['--secrets' => true])
        ->expectsOutputToContain('appstation.conf.local.json')
        ->expectsOutputToContain('APS_SIGNING_SECRET')
        ->doesntExpectOutputToContain('secret-key')
        ->doesntExpectOutputToContain('from-env')
        ->assertSuccessful();
});

it('flags a local config out of sync with the project environment', function () {
    file_put_contents($this->basePath.'/appstation.conf.local.json', json_encode([
        'environment' => 'production', 'auth' => ['apiKey' => 'k'], 'signingSecret' => 's',
    ]));

    $this->artisan('aps-connect:status', ['--secrets' => true])
        ->expectsOutputToContain('identifiants "production", pas "development"')
        ->assertSuccessful();
});
