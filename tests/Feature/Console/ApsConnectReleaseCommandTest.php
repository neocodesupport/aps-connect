<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Neocode\ApsConnect\Support\ApsCliSession;
use Neocode\ApsConnect\Support\ProjectConfigReader;
use Neocode\ApsConnect\Support\ReleaseArchiveBuilder;

function apsConnectReleaseCommandSoftwareConfig(): array
{
    return [
        'type' => 'software',
        'software' => ['name' => 'My Software'],
        'appstation' => ['softwareId' => 7, 'baseUrl' => 'https://app-station.neocode.ci'],
        'api' => ['baseUrl' => 'https://registra.neocode.ci/api'],
    ];
}

function apsConnectReleaseCommandFakeReleaseResponse(array $overrides = []): array
{
    return ['data' => array_merge([
        'id' => 1, 'version' => '1.0.0', 'platform' => 'universal', 'channel' => 'stable',
        'release_notes' => null, 'checksum' => 'sha256:abc', 'signature' => 'sig',
        'file_size' => 9, 'is_yanked' => false, 'published_at' => null,
    ], $overrides)];
}

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/aps-connect-release-cmd-tests-'.bin2hex(random_bytes(8));
    mkdir($this->basePath, recursive: true);

    file_put_contents($this->basePath.'/appstation.conf.json', json_encode(apsConnectReleaseCommandSoftwareConfig()));
    file_put_contents($this->basePath.'/composer.json', '{}');
    // No `environment` in the project config means production.
    file_put_contents($this->basePath.'/appstation.conf.local.json', json_encode([
        'environment' => 'production', 'auth' => ['apiKey' => 'prod-api-key'], 'signingSecret' => 'never-shipped',
    ]));

    app()->singleton(ProjectConfigReader::class, fn () => new ProjectConfigReader($this->basePath));
    app()->singleton(ReleaseArchiveBuilder::class, fn () => new ReleaseArchiveBuilder($this->basePath));

    // Never pick up the developer's real `aps login` session.
    $this->sessionPath = $this->basePath.'/aps-cli-credentials.json';
    app()->singleton(ApsCliSession::class, fn () => new ApsCliSession($this->sessionPath));

    $this->filePath = $this->basePath.'/release.zip';
    file_put_contents($this->filePath, 'zip-bytes');

    putenv('APS_TOKEN');
    putenv('APPSTATION_BASE_URL');

    Process::fake();
});

afterEach(function () {
    putenv('APS_TOKEN');
    putenv('APPSTATION_BASE_URL');

    foreach (glob($this->basePath.'/*') ?: [] as $file) {
        is_dir($file) || unlink($file);
    }

    @rmdir($this->basePath);
});

it('packs only when --pack is passed without --publish', function () {
    $out = $this->basePath.'/out.zip';

    $this->artisan('aps-connect:release', ['--pack' => true, '--out' => $out, '--skip-npm' => true, '--no-obfuscate' => true])
        ->expectsOutputToContain('Empaquetage de "My Software"')
        ->expectsOutputToContain('Suite : php artisan aps-connect:release --publish')
        ->assertSuccessful();

    expect(is_file($out))->toBeTrue();

    unlink($out);
});

it('publishes directly when --publish is passed with a file and all required options', function () {
    Http::fake(['*' => Http::response(apsConnectReleaseCommandFakeReleaseResponse(), 201)]);

    $this->artisan('aps-connect:release', [
        'file' => $this->filePath,
        '--publish' => true,
        '--release-version' => '1.0.0',
        '--channel' => 'stable',
        '--platform' => 'universal',
        '--token' => 'a-token',
    ])
        ->expectsOutputToContain('Release 1.0.0 (universal) publiée (stable).')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer a-token'));
});

it('chains pack into publish when both flags are passed', function () {
    Http::fake(['*' => Http::response(apsConnectReleaseCommandFakeReleaseResponse(), 201)]);

    $this->artisan('aps-connect:release', [
        '--pack' => true,
        '--publish' => true,
        '--skip-npm' => true,
        '--no-obfuscate' => true,
        '--release-version' => '1.0.0',
        '--token' => 'a-token',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Empaquetage de "My Software"')
        ->expectsOutputToContain('Release 1.0.0')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer a-token'));
});

it('asks what to do when neither --pack nor --publish is passed, then packs only', function () {
    $out = $this->basePath.'/out.zip';

    $this->artisan('aps-connect:release', ['--out' => $out, '--skip-npm' => true, '--no-obfuscate' => true])
        ->expectsChoice(
            'Que voulez-vous faire ?',
            'Empaqueter seulement',
            ['Empaqueter et publier', 'Empaqueter seulement', 'Publier un fichier existant'],
        )
        ->expectsOutputToContain('Empaquetage de "My Software"')
        ->assertSuccessful();

    expect(is_file($out))->toBeTrue();

    unlink($out);
});

it('asks for the file, version, channel, platform and token when publishing interactively', function () {
    Http::fake(['*' => Http::response(apsConnectReleaseCommandFakeReleaseResponse(), 201)]);

    $this->artisan('aps-connect:release')
        ->expectsChoice(
            'Que voulez-vous faire ?',
            'Publier un fichier existant',
            ['Empaqueter et publier', 'Empaqueter seulement', 'Publier un fichier existant'],
        )
        ->expectsQuestion('Fichier à publier (chemin)', $this->filePath)
        ->expectsChoice('Channel', 'stable', ['stable', 'beta', 'rc', 'nightly'])
        ->expectsChoice('Plateforme', 'universal', ['windows', 'macos', 'linux', 'android', 'ios', 'web', 'cli', 'browser_extension', 'universal'])
        ->expectsQuestion('Version (semver, ex. 1.4.0)', '1.0.0')
        ->expectsQuestion('Token éditeur (APS_TOKEN)', 'a-token')
        ->expectsOutputToContain('Release 1.0.0')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer a-token'));
});

it('fails gracefully when appstation.conf.json is missing', function () {
    unlink($this->basePath.'/appstation.conf.json');

    $this->artisan('aps-connect:release', ['--pack' => true])
        ->expectsOutputToContain('Configuration incomplète')
        ->assertFailed();
});

it('rejects an invalid semver version passed via option', function () {
    $this->artisan('aps-connect:release', [
        'file' => $this->filePath,
        '--publish' => true,
        '--release-version' => 'not-a-version',
        '--token' => 'a-token',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('doit être un semver valide')
        ->assertFailed();
});

it('rejects min/max software version for a non-module project', function () {
    $this->artisan('aps-connect:release', [
        'file' => $this->filePath,
        '--publish' => true,
        '--release-version' => '1.0.0',
        '--token' => 'a-token',
        '--min-software-version' => '1.0.0',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('ne sont disponibles que pour un module')
        ->assertFailed();
});

it('fails non-interactively when no token is available', function () {
    $this->artisan('aps-connect:release', [
        'file' => $this->filePath,
        '--publish' => true,
        '--release-version' => '1.0.0',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Aucun token éditeur')
        ->assertFailed();
});

it('publishes with the aps login session, against the instance it was created for', function () {
    Http::fake(['*' => Http::response(apsConnectReleaseCommandFakeReleaseResponse(), 201)]);

    file_put_contents($this->sessionPath, json_encode([
        'appstation' => ['baseUrl' => 'https://app-station.test/', 'accessToken' => 'session-token'],
    ]));

    $this->artisan('aps-connect:release', [
        'file' => $this->filePath,
        '--publish' => true,
        '--release-version' => '1.0.0',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Session aps login utilisée (https://app-station.test)')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer session-token')
        && str_starts_with($request->url(), 'https://app-station.test/api/v1/publisher/softwares/7/releases'));
});

it('prefers APS_TOKEN over the aps login session and sends it to APPSTATION_BASE_URL when set', function () {
    Http::fake(['*' => Http::response(apsConnectReleaseCommandFakeReleaseResponse(), 201)]);

    file_put_contents($this->sessionPath, json_encode([
        'appstation' => ['baseUrl' => 'https://app-station.test', 'accessToken' => 'session-token'],
    ]));
    putenv('APS_TOKEN=env-token');
    putenv('APPSTATION_BASE_URL=https://staging.app-station.test/');

    $this->artisan('aps-connect:release', [
        'file' => $this->filePath,
        '--publish' => true,
        '--release-version' => '1.0.0',
        '--no-interaction' => true,
    ])->assertSuccessful();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer env-token')
        && str_starts_with($request->url(), 'https://staging.app-station.test/api/v1/publisher/'));
});

it('sends APS_TOKEN to the project base url when APPSTATION_BASE_URL is not set', function () {
    Http::fake(['*' => Http::response(apsConnectReleaseCommandFakeReleaseResponse(), 201)]);

    putenv('APS_TOKEN=env-token');

    $this->artisan('aps-connect:release', [
        'file' => $this->filePath,
        '--publish' => true,
        '--release-version' => '1.0.0',
        '--no-interaction' => true,
    ])->assertSuccessful();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer env-token')
        && str_starts_with($request->url(), 'https://app-station.neocode.ci/api/v1/publisher/'));
});

it('ships the api key, but never the signing secret, in the packed archive', function () {
    $out = $this->basePath.'/out.zip';

    $this->artisan('aps-connect:release', ['--pack' => true, '--out' => $out, '--skip-npm' => true, '--no-obfuscate' => true])
        ->doesntExpectOutputToContain('clé Registra "')
        ->assertSuccessful();

    $zip = new ZipArchive;
    $zip->open($out);
    $shipped = $zip->getFromName('appstation.conf.local.json');
    $zip->close();
    unlink($out);

    expect(json_decode((string) $shipped, true))->toBe(['environment' => 'production', 'auth' => ['apiKey' => 'prod-api-key']])
        ->and($shipped)->not->toContain('never-shipped');
});

it('warns when packing with the development key', function () {
    file_put_contents($this->basePath.'/appstation.conf.json', json_encode([...apsConnectReleaseCommandSoftwareConfig(), 'environment' => 'development']));
    file_put_contents($this->basePath.'/appstation.conf.local.json', json_encode([
        'environment' => 'development', 'auth' => ['apiKey' => 'dev-api-key'], 'signingSecret' => 's',
    ]));
    $out = $this->basePath.'/out.zip';

    $this->artisan('aps-connect:release', ['--pack' => true, '--out' => $out, '--skip-npm' => true, '--no-obfuscate' => true])
        ->expectsOutputToContain('aps-connect:fetch-key --production')
        ->assertSuccessful();

    unlink($out);
});

it('refuses to pack without an api key or with another environment\'s credentials', function (array $local, string $message) {
    file_put_contents($this->basePath.'/appstation.conf.local.json', json_encode($local));

    $this->artisan('aps-connect:release', ['--pack' => true, '--skip-npm' => true, '--no-obfuscate' => true])
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'no api key' => [['environment' => 'production', 'signingSecret' => 's'], "n'a pas de clé API"],
    'development credentials' => [['environment' => 'development', 'auth' => ['apiKey' => 'k']], 'contient des identifiants "development", pas "production"'],
]);
