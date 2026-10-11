<?php

declare(strict_types=1);

use Neocode\ApsConnect\Support\ProjectConfigReader;

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/aps-connect-sign-tests-'.bin2hex(random_bytes(8));
    mkdir($this->basePath, recursive: true);

    file_put_contents($this->basePath.'/appstation.conf.json', json_encode([
        'type' => 'software',
        'environment' => 'development',
        'software' => ['token' => 'sw-tok', 'name' => 'My App'],
        'api' => ['baseUrl' => 'https://registra-dev.test/api'],
        'integration' => ['detectedStack' => 'php'],
        'appstation' => ['softwareId' => 7, 'projectFingerprint' => 'fp'],
    ]));
    file_put_contents($this->basePath.'/appstation.conf.local.json', json_encode([
        'environment' => 'development', 'auth' => ['apiKey' => 'secret-api-key'], 'signingSecret' => 'signing-secret',
    ]));

    app()->singleton(ProjectConfigReader::class, fn () => new ProjectConfigReader($this->basePath));
    $this->manifest = $this->basePath.'/manifest.json';

    putenv('APS_SIGNING_SECRET');
});

afterEach(function () {
    putenv('APS_SIGNING_SECRET');

    foreach (glob($this->basePath.'/*') ?: [] as $file) {
        unlink($file);
    }

    @rmdir($this->basePath);
});

it('signs a manifest that verifies, without the api key in clear', function () {
    $this->artisan('aps-connect:sign', ['--output' => $this->manifest])
        ->expectsOutputToContain('Manifest signé écrit')
        ->assertSuccessful();

    $contents = (string) file_get_contents($this->manifest);

    expect($contents)->not->toContain('secret-api-key')
        ->and($contents)->not->toContain('signing-secret')
        ->and(json_decode($contents, true)['payload']['registra']['apiKeyFingerprint'])->toBe(hash('sha256', 'secret-api-key'));

    $this->artisan('aps-connect:verify', ['file' => $this->manifest])
        ->expectsOutputToContain('Manifest valide')
        ->assertSuccessful();
});

it('rejects a tampered manifest', function () {
    $this->artisan('aps-connect:sign', ['--output' => $this->manifest])->assertSuccessful();

    $manifest = json_decode((string) file_get_contents($this->manifest), true);
    $manifest['payload']['software']['name'] = 'Someone Else';
    file_put_contents($this->manifest, json_encode($manifest));

    $this->artisan('aps-connect:verify', ['file' => $this->manifest])
        ->expectsOutputToContain('Signature HMAC-SHA256 invalide.')
        ->assertFailed();
});

it('verifies with APS_SIGNING_SECRET outside the signing repository', function () {
    $this->artisan('aps-connect:sign', ['--output' => $this->manifest])->assertSuccessful();

    unlink($this->basePath.'/appstation.conf.local.json');
    // A fresh reader, as a new `artisan` process would have (it caches files).
    app()->forgetInstance(ProjectConfigReader::class);

    $this->artisan('aps-connect:verify', ['file' => $this->manifest])
        ->expectsOutputToContain('signingSecret introuvable')
        ->assertFailed();

    putenv('APS_SIGNING_SECRET=signing-secret');

    $this->artisan('aps-connect:verify', ['file' => $this->manifest])->assertSuccessful();
});

it('refuses to sign while the local config is for another environment', function () {
    file_put_contents($this->basePath.'/appstation.conf.local.json', json_encode([
        'environment' => 'production', 'auth' => ['apiKey' => 'k'], 'signingSecret' => 's',
    ]));

    $this->artisan('aps-connect:sign', ['--output' => $this->manifest])
        ->expectsOutputToContain('aps-connect:fetch-key')
        ->assertFailed();

    expect(is_file($this->manifest))->toBeFalse();
});

it('fails to sign an unlinked project', function () {
    unlink($this->basePath.'/appstation.conf.json');

    $this->artisan('aps-connect:sign')
        ->expectsOutputToContain('aps-connect:init')
        ->assertFailed();
});

it('reports a missing, non-JSON or malformed manifest', function () {
    $this->artisan('aps-connect:verify', ['file' => $this->manifest])
        ->expectsOutputToContain('Fichier introuvable')
        ->assertFailed();

    file_put_contents($this->manifest, '{nope');
    $this->artisan('aps-connect:verify', ['file' => $this->manifest])
        ->expectsOutputToContain('JSON invalide')
        ->assertFailed();

    file_put_contents($this->manifest, '{"payload": {}}');
    $this->artisan('aps-connect:verify', ['file' => $this->manifest])
        ->expectsOutputToContain('Structure de manifest invalide')
        ->assertFailed();
});
