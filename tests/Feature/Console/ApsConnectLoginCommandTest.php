<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Neocode\ApsConnect\Data\PublisherCredentials;
use Neocode\ApsConnect\Support\ApsCliSession;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/aps-connect-login-tests-'.bin2hex(random_bytes(8));
    $this->sessionPath = $this->dir.'/app-station/credentials.json';

    app()->singleton(ApsCliSession::class, fn () => new ApsCliSession($this->sessionPath));

    putenv('APS_TOKEN');
    putenv('APPSTATION_BASE_URL');
});

afterEach(function () {
    putenv('APS_TOKEN');
    putenv('APPSTATION_BASE_URL');

    @unlink($this->sessionPath);
    @rmdir(dirname($this->sessionPath));
    @rmdir($this->dir);
});

it('checks the token then stores it where aps-cli does, in its layout', function () {
    Http::fake(['app-station.neocode.ci/api/v1/developer/softwares*' => Http::response(['data' => []])]);

    $this->artisan('aps-connect:login', ['--token' => ' a-token ', '--no-interaction' => true])
        ->expectsOutputToContain('Connecté à https://app-station.neocode.ci.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer a-token'));

    expect(file_get_contents($this->sessionPath))->toBe(<<<'JSON'
        {
          "appstation": {
            "baseUrl": "https://app-station.neocode.ci",
            "accessToken": "a-token"
          }
        }

        JSON);
});

it('targets the dev instance with --dev, unless a base url is given', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    $this->artisan('aps-connect:login', ['--token' => 'a-token', '--dev' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Connecté à https://app-station.test.')
        ->assertSuccessful();

    $this->artisan('aps-connect:login', ['--token' => 'a-token', '--dev' => true, '--base-url' => 'https://staging.test/', '--no-interaction' => true])
        ->expectsOutputToContain('Connecté à https://staging.test.')
        ->assertSuccessful();
});

it('reads the token and base url from APS_TOKEN and APPSTATION_BASE_URL', function () {
    Http::fake(['staging.test/*' => Http::response(['data' => []])]);
    putenv('APS_TOKEN=env-token');
    putenv('APPSTATION_BASE_URL=https://staging.test');

    $this->artisan('aps-connect:login', ['--no-interaction' => true])->assertSuccessful();

    expect((new ApsCliSession($this->sessionPath))->credentials()?->token)->toBe('env-token');
});

it('asks for the token interactively', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    $this->artisan('aps-connect:login')
        ->expectsQuestion('Token éditeur (App Station → Paramètres → CLI, sur https://app-station.neocode.ci)', 'typed-token')
        ->assertSuccessful();

    expect((new ApsCliSession($this->sessionPath))->credentials()?->token)->toBe('typed-token');
});

it('does not store a token App Station rejects', function () {
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('aps-connect:login', ['--token' => 'bad-token', '--no-interaction' => true])
        ->expectsOutputToContain('Impossible de valider le token')
        ->assertFailed();

    expect(is_file($this->sessionPath))->toBeFalse();
});

it('fails non-interactively without a token', function () {
    $this->artisan('aps-connect:login', ['--no-interaction' => true])
        ->expectsOutputToContain('Aucun token')
        ->assertFailed();
});

it('logs out by deleting the shared session', function () {
    (new ApsCliSession($this->sessionPath))->store(new PublisherCredentials('a-token', 'https://app-station.test'));

    $this->artisan('aps-connect:logout')
        ->expectsOutputToContain('Déconnecté.')
        ->assertSuccessful();

    expect(is_file($this->sessionPath))->toBeFalse();

    $this->artisan('aps-connect:logout')
        ->expectsOutputToContain('déjà déconnecté')
        ->assertSuccessful();
});
