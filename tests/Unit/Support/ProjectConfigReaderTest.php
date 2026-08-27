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
        ['environment' => 'development', 'api' => ['baseUrl' => 'https://registra.example.com/api']],
        ['auth' => ['apiKey' => 'from-local-file']],
    );

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->apiKey)->toBe('from-local-file');
    expect($credentials->baseUrl)->toBe('https://registra.example.com/api');
    expect($credentials->environment)->toBe('development');
});

it('prefers explicit config over the project files for the api key only', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'development', 'api' => ['baseUrl' => 'https://registra.example.com/api']],
        ['auth' => ['apiKey' => 'from-local-file']],
    );

    config(['aps-connect.dev_api_key' => 'from-config']);

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->apiKey)->toBe('from-config');
});

it('never lets a config default override an explicit base url in the project file, to prevent redirecting licence checks to a rogue server', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'production', 'api' => ['baseUrl' => 'https://registra.example.com/api']],
        ['auth' => ['apiKey' => 'prod-key']],
    );

    // appstation.conf.json already declares an explicit api.baseUrl above —
    // this simulates something (a deployer's own service provider, a stray
    // env var read elsewhere) setting the config default anyway at runtime,
    // and confirms it never overrides the explicit, versioned file value.
    config(['aps-connect.registra_base_url' => 'https://attacker.example/api']);

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->baseUrl)->toBe('https://registra.example.com/api');
});

it('uses the dev api key when the project file declares the development environment', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'development', 'api' => ['baseUrl' => 'https://registra.example.com/api']],
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
        ['environment' => 'production', 'api' => ['baseUrl' => 'https://registra.example.com/api']],
        null,
    );

    config(['aps-connect.dev_api_key' => 'dev-key', 'aps-connect.api_key' => 'prod-key']);

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->environment)->toBe('production');
    expect($credentials->apiKey)->toBe('prod-key');
});

it('defaults to production when the project file does not declare an environment', function () {
    writeApsConnectProjectFixtures($this->basePath, ['api' => ['baseUrl' => 'https://registra.example.com/api']], null);

    config(['aps-connect.api_key' => 'prod-key']);

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->environment)->toBe('production');
});

it('throws when no api key can be resolved', function () {
    writeApsConnectProjectFixtures($this->basePath, ['api' => ['baseUrl' => 'https://registra.example.com/api']], null);

    (new ProjectConfigReader($this->basePath))->credentials();
})->throws(MissingCredentialsException::class);

it('falls back to the config default when the project file has no api.baseUrl', function () {
    writeApsConnectProjectFixtures($this->basePath, ['environment' => 'production'], ['auth' => ['apiKey' => 'a-key']]);

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->baseUrl)->toBe(config('aps-connect.registra_base_url'));
});

it('falls back to the config default when there is no project file at all', function () {
    config(['aps-connect.api_key' => 'prod-key']);

    $credentials = (new ProjectConfigReader($this->basePath))->credentials();

    expect($credentials->baseUrl)->toBe(config('aps-connect.registra_base_url'));
    expect($credentials->environment)->toBe('production');
});

it('throws when neither the project file nor the config default provide a base url', function () {
    writeApsConnectProjectFixtures($this->basePath, ['environment' => 'production'], ['auth' => ['apiKey' => 'a-key']]);

    config(['aps-connect.registra_base_url' => null]);

    (new ProjectConfigReader($this->basePath))->credentials();
})->throws(MissingCredentialsException::class);

it('resolves App Station credentials from the project file, reusing the Registra api key', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        [
            'environment' => 'production',
            'api' => ['baseUrl' => config('aps-connect.registra_base_url')],
            'appstation' => ['baseUrl' => 'https://app-station.example.com'],
        ],
        null,
    );

    $registraCredentials = new RegistraCredentials('product-key', config('aps-connect.registra_base_url'), 'production');

    $credentials = (new ProjectConfigReader($this->basePath))->appStationCredentials($registraCredentials);

    expect($credentials->apiKey)->toBe('product-key');
    expect($credentials->baseUrl)->toBe('https://app-station.example.com');
});

it('never lets a config default override an explicit App Station base url in the project file, to prevent redirecting distribution calls to a rogue server', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        [
            'environment' => 'production',
            'api' => ['baseUrl' => config('aps-connect.registra_base_url')],
            'appstation' => ['baseUrl' => 'https://app-station.example.com'],
        ],
        null,
    );

    config(['aps-connect.appstation_base_url' => 'https://attacker.example/api']);

    $registraCredentials = new RegistraCredentials('product-key', config('aps-connect.registra_base_url'), 'production');

    $credentials = (new ProjectConfigReader($this->basePath))->appStationCredentials($registraCredentials);

    expect($credentials->baseUrl)->toBe('https://app-station.example.com');
});

it('falls back to the config default when the project file has no appstation.baseUrl', function () {
    writeApsConnectProjectFixtures(
        $this->basePath,
        ['environment' => 'production', 'api' => ['baseUrl' => config('aps-connect.registra_base_url')]],
        null,
    );

    $registraCredentials = new RegistraCredentials('product-key', config('aps-connect.registra_base_url'), 'production');

    $credentials = (new ProjectConfigReader($this->basePath))->appStationCredentials($registraCredentials);

    expect($credentials->baseUrl)->toBe(config('aps-connect.appstation_base_url'));
});

it('falls back to the config default when there is no project file at all for App Station credentials', function () {
    $registraCredentials = new RegistraCredentials('product-key', config('aps-connect.registra_base_url'), 'production');

    $credentials = (new ProjectConfigReader($this->basePath))->appStationCredentials($registraCredentials);

    expect($credentials->baseUrl)->toBe(config('aps-connect.appstation_base_url'));
});

it('throws when neither the project file nor the config default provide an App Station base url', function () {
    config(['aps-connect.appstation_base_url' => null]);

    $registraCredentials = new RegistraCredentials('product-key', config('aps-connect.registra_base_url'), 'production');

    (new ProjectConfigReader($this->basePath))->appStationCredentials($registraCredentials);
})->throws(MissingCredentialsException::class);
