<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Support;

use Neocode\ApsConnect\Data\AppStationCredentials;
use Neocode\ApsConnect\Data\RegistraCredentials;
use Neocode\ApsConnect\Exceptions\MissingCredentialsException;

/**
 * Resolves Registra credentials the same way the `aps` CLI's own project
 * files are laid out. appstation.conf.json (versioned, publisher-controlled,
 * always present unlike the gitignored local file) is the trust anchor: an
 * explicit `api.baseUrl` or `environment` in it always wins over anything
 * else. The api key additionally has a Laravel config/env override, because
 * appstation.conf.local.json is gitignored — it simply does not exist in
 * most CI/CD pipelines, so an env var is the only way to deliver the key
 * there, and a wrong/forged key is rejected by Registra anyway (nothing to
 * gain by overriding it). The base url also has a config fallback — see
 * resolveBaseUrl() — used only when the project file is silent, never to
 * override an explicit value in it. The environment has no fallback at all:
 * no project file (or no `environment` key in it) always means "production"
 * — see resolveEnvironment().
 */
final class ProjectConfigReader
{
    public function __construct(private readonly string $basePath) {}

    public function credentials(): RegistraCredentials
    {
        $projectConfig = $this->readJsonFile($this->basePath.'/appstation.conf.json');
        $localConfig = $this->readJsonFile($this->basePath.'/appstation.conf.local.json');

        $environment = $this->resolveEnvironment($projectConfig);

        $apiKeyConfigKey = $environment === 'development' ? 'dev_api_key' : 'api_key';

        $apiKey = config("aps-connect.{$apiKeyConfigKey}") ?? $localConfig['auth']['apiKey'] ?? null;
        $baseUrl = $this->resolveBaseUrl($projectConfig);

        if (! is_string($apiKey) || $apiKey === '') {
            throw new MissingCredentialsException(
                "No Registra API key configured. Set config('aps-connect.{$apiKeyConfigKey}') / its env var, ".
                'or run `aps init` to write appstation.conf.local.json.',
            );
        }

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new MissingCredentialsException(
                'No Registra base URL configured: appstation.conf.json has no `api.baseUrl`, and '.
                "config('aps-connect.registra_base_url') is empty.",
            );
        }

        return new RegistraCredentials($apiKey, $baseUrl, $environment);
    }

    /**
     * Resolves credentials for the App Station distribution endpoints
     * (`instances/register`, `packages/{id}/download`, `updates/check`). Reuses
     * the already-resolved Registra api key as-is: `instances/register` is
     * authenticated with the exact same product `X-Software-Api-Key` as every
     * Registra call — App Station's Software record syncs its accepted keys
     * from Registra's own secrets — so re-deriving it here would just
     * duplicate ProjectConfigReader::credentials()'s env/config resolution.
     *
     * Unlike Registra's `api.baseUrl` — which genuinely varies (App Station's
     * own `/registra/init` returns it dynamically per software/environment,
     * see the `aps` CLI) — App Station itself has exactly one production
     * address, `https://app-station.neocode.ci`, hardcoded as `aps login`'s
     * own default in the `aps` CLI. So `appstation.conf.json`'s
     * `appstation.baseUrl` still wins when present (it's the versioned,
     * publisher-controlled value), but when it's absent this falls back to
     * `config('aps-connect.appstation_base_url')` — a plain literal in
     * config/aps-connect.php, deliberately not wrapped in `env()`, so a
     * deployer's .env can't redirect these calls the way it could with a
     * `env()`-backed default.
     */
    public function appStationCredentials(RegistraCredentials $registraCredentials): AppStationCredentials
    {
        $projectConfig = $this->readJsonFile($this->basePath.'/appstation.conf.json');

        $baseUrl = $projectConfig['appstation']['baseUrl'] ?? config('aps-connect.appstation_base_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new MissingCredentialsException(
                'No App Station base URL configured: appstation.conf.json has no `appstation.baseUrl`, and '.
                "config('aps-connect.appstation_base_url') is empty.",
            );
        }

        return new AppStationCredentials($registraCredentials->apiKey, $baseUrl);
    }

    /**
     * appstation.conf.json (written by `aps init` / flipped by `aps
     * promote`) is the ONLY source for the environment — it is versioned
     * and controlled by the publisher, unlike APP_ENV or any other ambient
     * env var, which a deployer of the licensed application can freely
     * change. Sourcing this from an env var would let anyone flip to
     * "development" and get routed to Registra's sandbox — which accepts
     * reserved dev licence keys — bypassing real licence checks. No project
     * file (or no `environment` key in it) means "production", full stop.
     *
     * @param  array<string, mixed>  $projectConfig
     */
    private function resolveEnvironment(array $projectConfig): string
    {
        if (isset($projectConfig['environment']) && is_string($projectConfig['environment']) && $projectConfig['environment'] !== '') {
            return $projectConfig['environment'];
        }

        return 'production';
    }

    /**
     * appstation.conf.json's `api.baseUrl` is the trust anchor: it
     * determines which server every "is this licence valid" call is sent
     * to, so an explicit value here always wins — it is versioned and
     * publisher-controlled, unlike an env var anyone deploying the licensed
     * application could edit in their own .env. When the project file is
     * silent, this falls back to `config('aps-connect.registra_base_url')`
     * — a plain literal in config/aps-connect.php pointing at Registra's
     * real shared production instance, deliberately not wrapped in `env()`
     * so a deployer's .env still can't redirect verification calls the way
     * it could with an `env()`-backed default.
     *
     * @param  array<string, mixed>  $projectConfig
     */
    private function resolveBaseUrl(array $projectConfig): ?string
    {
        $projectBaseUrl = $projectConfig['api']['baseUrl'] ?? config('aps-connect.registra_base_url');

        return is_string($projectBaseUrl) && $projectBaseUrl !== '' ? $projectBaseUrl : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
