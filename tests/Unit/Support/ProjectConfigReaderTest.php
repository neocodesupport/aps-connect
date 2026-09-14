<?php

declare(strict_types=1);

use Neocode\ApsConnect\Data\RegistraCredentials;
use Neocode\ApsConnect\Exceptions\MissingCredentialsException;
use Neocode\ApsConnect\Support\ProjectConfigReader;

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
});

afterEach(function () {
    foreach (glob($this->basePath.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->basePath);
});

it('resolves credentials entirely from the project files', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'development', 'api' => ['baseUrl' => 'https://registra.neocode.ci/api']],
        ['auth' => ['apiKey' => 'from-local-file']],
    );

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->apiKey)->toBe('from-local-file');
    expect($credentials->baseUrl)->toBe('https://registra.neocode.ci/api');
    expect($credentials->environment)->toBe('development');
});

it('never lets a stray config value override the api key from appstation.conf.local.json', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'production', 'api' => ['baseUrl' => 'https://registra.neocode.ci/api']],
        ['auth' => ['apiKey' => 'from-local-file']],
    );

    config(['aps-connect.api_key' => 'should-be-ignored']);

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->apiKey)->toBe('from-local-file');
});

it('uses the development environment declared in the project file', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'development', 'api' => ['baseUrl' => 'https://registra.neocode.ci/api']],
        ['auth' => ['apiKey' => 'dev-key']],
    );

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->environment)->toBe('development');
    expect($credentials->apiKey)->toBe('dev-key');
});

it('uses the production environment declared in the project file', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'production', 'api' => ['baseUrl' => 'https://registra.neocode.ci/api']],
        ['auth' => ['apiKey' => 'prod-key']],
    );

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->environment)->toBe('production');
    expect($credentials->apiKey)->toBe('prod-key');
});

it('defaults to production when the project file does not declare an environment', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['api' => ['baseUrl' => 'https://registra.neocode.ci/api']],
        ['auth' => ['apiKey' => 'prod-key']],
    );

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->environment)->toBe('production');
});

it('throws when no api key can be resolved', function () {
    writeApsConnectProjectFixtures($this->basePath, ['api' => ['baseUrl' => 'https://registra.neocode.ci/api']], null);

    (new ProjectConfigReader($this->basePath))->credentials();
})->throws(MissingCredentialsException::class);

it('throws when appstation.conf.local.json has no auth.apiKey', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['api' => ['baseUrl' => 'https://registra.neocode.ci/api']],
        ['auth' => []],
    );

    (new ProjectConfigReader($this->basePath))->credentials();
})->throws(MissingCredentialsException::class);

it('throws when the project file has no api.baseUrl, since the base url has no config/env fallback', function () {
    writeApsConnectProjectFixtures($this->basePath, ['environment' => 'production'], ['auth' => ['apiKey' => 'a-key']]);

    (new ProjectConfigReader($this->basePath))->credentials();
})->throws(MissingCredentialsException::class);

it('throws when there is no project file at all, since the base url has no config/env fallback', function () {
    writeApsConnectProjectFixtures($this->basePath, null, ['auth' => ['apiKey' => 'prod-key']]);

    (new ProjectConfigReader($this->basePath))->credentials();
})->throws(MissingCredentialsException::class);

it('throws when api.baseUrl is not the expected object shape', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'production', 'api' => 'https://registra.neocode.ci/api'],
        ['auth' => ['apiKey' => 'a-key']],
    );

    (new ProjectConfigReader($this->basePath))->credentials();
})->throws(MissingCredentialsException::class);

it('resolves App Station credentials from the project file, reusing the Registra api key', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        [
            'environment' => 'production',
            'api' => ['baseUrl' => 'https://registra.neocode.ci/api'],
            'appstation' => ['baseUrl' => 'https://app-station.neocode.ci'],
        ],
        null,
    );

    $registraCredentials = new RegistraCredentials('product-key', 'https://registra.neocode.ci/api', 'production');

    $credentials = (new ProjectConfigReader($this->basePath))->appStationCredentials($registraCredentials);

    expect($credentials->apiKey)->toBe('product-key');
    expect($credentials->baseUrl)->toBe('https://app-station.neocode.ci');
});

it('throws when the project file has no appstation.baseUrl, since the base url has no config/env fallback', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'production', 'api' => ['baseUrl' => 'https://registra.neocode.ci/api']],
        null,
    );

    $registraCredentials = new RegistraCredentials('product-key', 'https://registra.neocode.ci/api', 'production');

    (new ProjectConfigReader($this->basePath))->appStationCredentials($registraCredentials);
})->throws(MissingCredentialsException::class);

it('throws when there is no project file at all for App Station credentials', function () {
    $registraCredentials = new RegistraCredentials('product-key', 'https://registra.neocode.ci/api', 'production');

    (new ProjectConfigReader($this->basePath))->appStationCredentials($registraCredentials);
})->throws(MissingCredentialsException::class);

it('throws when appstation.baseUrl is not the expected object shape', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        [
            'environment' => 'production',
            'api' => ['baseUrl' => 'https://registra.neocode.ci/api'],
            'appstation' => 'https://app-station.neocode.ci',
        ],
        null,
    );

    $registraCredentials = new RegistraCredentials('product-key', 'https://registra.neocode.ci/api', 'production');

    (new ProjectConfigReader($this->basePath))->appStationCredentials($registraCredentials);
})->throws(MissingCredentialsException::class);
