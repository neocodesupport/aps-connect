<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
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

    app()->singleton(ProjectConfigReader::class, fn () => new ProjectConfigReader($this->basePath));
    app()->singleton(ReleaseArchiveBuilder::class, fn () => new ReleaseArchiveBuilder($this->basePath));

    $this->filePath = $this->basePath.'/release.zip';
    file_put_contents($this->filePath, 'zip-bytes');

    putenv('APS_TOKEN');

    Process::fake();
});

afterEach(function () {
    putenv('APS_TOKEN');

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
