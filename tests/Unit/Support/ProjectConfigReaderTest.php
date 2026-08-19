<?php

declare(strict_types=1);

use ApsConnect\ApsConnect\Exceptions\MissingCredentialsException;
use ApsConnect\ApsConnect\Support\ProjectConfigReader;

/**
 * @param  array<string, mixed>|null  $projectConfig
 * @param  array<string, mixed>|null  $localConfig
 */
function writeApsConnectProjectFixtures(string $basePath, ?array $projectConfig, ?array $localConfig): void
{
    if ($projectConfig !== null) {
        file_put_contents($basePath.'/appstation.conf.json', json_encode($projectConfig));
    }

    if ($localConfig !== null) {
        file_put_contents($basePath.'/appstation.conf.local.json', json_encode($localConfig));
    }
}

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/aps-connect-tests-'.uniqid();
    mkdir($this->basePath, recursive: true);

    config([
        'aps-connect.api_key' => null,
        'aps-connect.dev_api_key' => null,
    ]);
});

afterEach(function () {
    foreach (glob($this->basePath.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->basePath);
});

it('resolves credentials entirely from the project files when no config override is set', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'development', 'api' => ['baseUrl' => 'https://registra.test/api']],
        ['auth' => ['apiKey' => 'from-local-file']],
    );

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->apiKey)->toBe('from-local-file');
    expect($credentials->baseUrl)->toBe('https://registra.test/api');
    expect($credentials->environment)->toBe('development');
});

it('prefers explicit config over the project files for the api key only', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'development', 'api' => ['baseUrl' => 'https://registra.test/api']],
        ['auth' => ['apiKey' => 'from-local-file']],
    );

    config(['aps-connect.dev_api_key' => 'from-config']);

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->apiKey)->toBe('from-config');
});

it('never lets a config/env value affect the base url, to prevent redirecting licence checks to a rogue server', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'production', 'api' => ['baseUrl' => 'https://registra.test/api']],
        ['auth' => ['apiKey' => 'prod-key']],
    );

    // There is no 'aps-connect.base_url' config key at all anymore — this
    // simulates something (a deployer's own service provider, a stray env
    // var read elsewhere) setting one anyway at runtime, and confirms it is
    // never consulted for the base url.
    config(['aps-connect.base_url' => 'https://attacker.example/api']);

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->baseUrl)->toBe('https://registra.test/api');
});

it('uses the dev api key when the project file declares the development environment', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'development', 'api' => ['baseUrl' => 'https://registra.test/api']],
        null,
    );

    config(['aps-connect.dev_api_key' => 'dev-key', 'aps-connect.api_key' => 'prod-key']);

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->environment)->toBe('development');
    expect($credentials->apiKey)->toBe('dev-key');
});

it('uses the production api key when the project file declares the production environment', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'production', 'api' => ['baseUrl' => 'https://registra.test/api']],
        null,
    );

    config(['aps-connect.dev_api_key' => 'dev-key', 'aps-connect.api_key' => 'prod-key']);

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->environment)->toBe('production');
    expect($credentials->apiKey)->toBe('prod-key');
});

it('defaults to production when the project file does not declare an environment', function () {
    writeApsConnectProjectFixtures($this->basePath, ['api' => ['baseUrl' => 'https://registra.test/api']], null);

    config(['aps-connect.api_key' => 'prod-key']);

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->environment)->toBe('production');
});

it('throws when no api key can be resolved', function () {
    writeApsConnectProjectFixtures($this->basePath, ['api' => ['baseUrl' => 'https://registra.test/api']], null);

    (new ProjectConfigReader($this->basePath))->credentials();
})->throws(MissingCredentialsException::class);

it('throws when the project file has no base url', function () {
    writeApsConnectProjectFixtures($this->basePath, ['environment' => 'production'], ['auth' => ['apiKey' => 'a-key']]);

    (new ProjectConfigReader($this->basePath))->credentials();
})->throws(MissingCredentialsException::class);

it('throws when there is no project file at all, since the base url has no config/env fallback', function () {
    config(['aps-connect.api_key' => 'prod-key']);

    (new ProjectConfigReader($this->basePath))->credentials();
})->throws(MissingCredentialsException::class);
