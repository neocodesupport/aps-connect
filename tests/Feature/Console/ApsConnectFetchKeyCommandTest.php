<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Neocode\ApsConnect\Support\ApsCliSession;
use Neocode\ApsConnect\Support\ProjectConfigReader;
use Neocode\ApsConnect\Support\ProjectConfigWriter;
use Neocode\ApsConnect\Support\ProjectInspector;

function apsConnectFetchKeyProjectConfig(string $environment = 'development'): array
{
    return [
        '$schema' => 'https://appstation.dev/schemas/appstation.conf.v1.json',
        'version' => 1,
        'type' => 'software',
        'environment' => $environment,
        'software' => ['token' => 'sw-token', 'name' => 'My App'],
        'api' => ['baseUrl' => 'https://registra-dev.test/api'],
        'auth' => ['apiKeySource' => 'env', 'apiKeyEnvVar' => 'REGISTRA_API_KEY'],
        'integration' => ['detectedStack' => 'php'],
        'appstation' => ['baseUrl' => 'https://app-station.test', 'softwareId' => 7, 'projectFingerprint' => 'fp-123'],
    ];
}

function apsConnectFetchKeyRegistraInit(string $environment, string $apiKey): array
{
    return [
        'software' => ['id' => 7, 'token' => 'sw-token', 'name' => 'My App', 'type' => 'software'],
        'registra' => [
            'baseUrl' => $environment === 'production' ? 'https://registra.test/api' : 'https://registra-dev.test/api',
            'environment' => $environment,
            'apiKey' => $apiKey,
        ],
        'signingSecret' => "{$environment}-secret",
        'projectId' => null,
    ];
}

function apsConnectFetchKeyLinkResponse(string $status = 'draft'): array
{
    return ['data' => ['id' => 7, 'name' => 'My App', 'slug' => 'my-app', 'status' => $status], 'registra_dev_sync_error' => null];
}

function apsConnectFetchKeyJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true);
}

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/aps-connect-fetch-key-tests-'.bin2hex(random_bytes(8));
    mkdir($this->basePath, recursive: true);

    file_put_contents($this->basePath.'/appstation.conf.json', json_encode(apsConnectFetchKeyProjectConfig()));
    file_put_contents($this->basePath.'/appstation.conf.local.json', json_encode([
        'environment' => 'development', 'auth' => ['apiKey' => 'old-key'], 'signingSecret' => 'old-secret',
    ]));

    $this->sessionPath = $this->basePath.'/aps-cli-credentials.json';
    file_put_contents($this->sessionPath, json_encode([
        'appstation' => ['baseUrl' => 'https://app-station.test', 'accessToken' => 'session-token'],
    ]));

    app()->singleton(ApsCliSession::class, fn () => new ApsCliSession($this->sessionPath));
    app()->singleton(ProjectConfigReader::class, fn () => new ProjectConfigReader($this->basePath));
    app()->singleton(ProjectConfigWriter::class, fn () => new ProjectConfigWriter($this->basePath));
    app()->singleton(ProjectInspector::class, fn () => new ProjectInspector($this->basePath));

    putenv('APS_TOKEN');
    putenv('APPSTATION_BASE_URL');

    // `git ls-files --error-unmatch` fails: the local config is not tracked.
    Process::fake(['*' => Process::result(exitCode: 1)]);
});

afterEach(function () {
    foreach (glob($this->basePath.'/*') ?: [] as $file) {
        unlink($file);
    }

    @rmdir($this->basePath);
});

it('refreshes the key of the current environment without touching appstation.conf.json', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares/7/link' => Http::response(apsConnectFetchKeyLinkResponse()),
        'app-station.test/api/v1/developer/registra/init' => Http::response(apsConnectFetchKeyRegistraInit('development', 'new-dev-key')),
    ]);
    $before = file_get_contents($this->basePath.'/appstation.conf.json');

    $this->artisan('aps-connect:fetch-key', ['--no-interaction' => true])
        ->expectsOutputToContain('clé Registra courante (development)')
        ->assertSuccessful();

    expect(apsConnectFetchKeyJson($this->basePath.'/appstation.conf.local.json'))->toBe([
        'environment' => 'development', 'auth' => ['apiKey' => 'new-dev-key'], 'signingSecret' => 'development-secret',
    ])->and(file_get_contents($this->basePath.'/appstation.conf.json'))->toBe($before);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/softwares/7/link')
        && $request['project_fingerprint'] === 'fp-123' && $request['force'] === false);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/registra/init')
        && $request['environment'] === 'development' && $request['id'] === 7);
});

it('switches both files to production with --production', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares/7/link' => Http::response(apsConnectFetchKeyLinkResponse('pending_review')),
        'app-station.test/api/v1/developer/registra/init' => Http::response(apsConnectFetchKeyRegistraInit('production', 'prod-key')),
    ]);

    $this->artisan('aps-connect:fetch-key', ['--production' => true, '--no-interaction' => true])
        ->expectsOutputToContain('basculés vers production')
        ->doesntExpectOutputToContain('Statut App Station actuel')
        ->assertSuccessful();

    $config = apsConnectFetchKeyJson($this->basePath.'/appstation.conf.json');

    expect($config['environment'])->toBe('production')
        ->and($config['api']['baseUrl'])->toBe('https://registra.test/api')
        ->and($config['integration']['detectedStack'])->toBe('php')
        ->and($config['appstation'])->toBe(['baseUrl' => 'https://app-station.test', 'softwareId' => 7, 'projectFingerprint' => 'fp-123'])
        ->and(apsConnectFetchKeyJson($this->basePath.'/appstation.conf.local.json'))->toBe([
            'environment' => 'production', 'auth' => ['apiKey' => 'prod-key'], 'signingSecret' => 'production-secret',
        ]);
});

it('warns that the production key is inactive until the software is submitted', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares/7/link' => Http::response(apsConnectFetchKeyLinkResponse('draft')),
        'app-station.test/api/v1/developer/registra/init' => Http::response(apsConnectFetchKeyRegistraInit('production', 'prod-key')),
    ]);

    $this->artisan('aps-connect:fetch-key', ['--production' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Statut App Station actuel : "draft"')
        ->assertSuccessful();
});

it('flags a local config that git tracks when switching to production', function () {
    Process::fake(['*' => Process::result('appstation.conf.local.json')]);
    Http::fake([
        'app-station.test/api/v1/developer/softwares/7/link' => Http::response(apsConnectFetchKeyLinkResponse('published')),
        'app-station.test/api/v1/developer/registra/init' => Http::response(apsConnectFetchKeyRegistraInit('production', 'prod-key')),
    ]);

    $this->artisan('aps-connect:fetch-key', ['--production' => true, '--no-interaction' => true])
        ->expectsOutputToContain('git rm --cached appstation.conf.local.json')
        ->assertSuccessful();
});

it('refuses --production when the project is already in production', function () {
    file_put_contents($this->basePath.'/appstation.conf.json', json_encode(apsConnectFetchKeyProjectConfig('production')));

    $this->artisan('aps-connect:fetch-key', ['--production' => true, '--no-interaction' => true])
        ->expectsOutputToContain('déjà configuré en production')
        ->assertFailed();
});

it('does not steal the link from another repository', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares/7/link' => Http::response(['message' => 'Already linked.'], 409),
    ]);

    $this->artisan('aps-connect:fetch-key', ['--no-interaction' => true])
        ->expectsOutputToContain('déjà lié à un autre dépôt local')
        ->assertFailed();

    expect(apsConnectFetchKeyJson($this->basePath.'/appstation.conf.local.json')['auth']['apiKey'])->toBe('old-key');
});

it('fails without appstation.conf.json', function () {
    unlink($this->basePath.'/appstation.conf.json');

    $this->artisan('aps-connect:fetch-key', ['--no-interaction' => true])
        ->expectsOutputToContain('aps-connect:init')
        ->assertFailed();
});

it('fails without a publisher session', function () {
    unlink($this->sessionPath);

    $this->artisan('aps-connect:fetch-key', ['--no-interaction' => true])
        ->expectsOutputToContain('Vous devez être connecté')
        ->assertFailed();
});
