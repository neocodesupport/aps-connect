<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Support;

use ApsConnect\ApsConnect\Data\RegistraCredentials;
use ApsConnect\ApsConnect\Exceptions\MissingCredentialsException;

/**
 * Resolves Registra credentials the same way the `aps` CLI's own project
 * files are laid out. The api key is the one value with a Laravel config/
 * env override, because appstation.conf.local.json is gitignored — it
 * simply does not exist in most CI/CD pipelines, so an env var is the only
 * way to deliver the key there, and a wrong/forged key is rejected by
 * Registra anyway (nothing to gain by overriding it). The base url and
 * environment are different: which server gets asked "is this licence
 * valid" is the actual trust anchor, so they come ONLY from
 * appstation.conf.json (versioned, publisher-controlled, always present
 * unlike the gitignored local file) — see resolveBaseUrl() and
 * resolveEnvironment(). No project file means credentials cannot resolve
 * at all for the base url, and "production" for the environment.
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
                'No Registra base URL configured: appstation.conf.json is missing or has no `api.baseUrl`. '.
                'Run `aps init` to write it — this value is not configurable through Laravel config/env.',
            );
        }

        return new RegistraCredentials($apiKey, $baseUrl, $environment);
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
     * appstation.conf.json's `api.baseUrl` is the ONLY source: it
     * determines which server every "is this licence valid" call is sent
     * to, so it is the actual trust anchor, not merely a convenience
     * default. There is deliberately no Laravel config/env fallback —
     * unlike appstation.conf.local.json (gitignored, may not exist in
     * CI/CD), appstation.conf.json is versioned and always present once
     * `aps init` has run, so an env var escape hatch would only ever serve
     * to let a deployer point verification calls at their own server that
     * always answers "active".
     *
     * @param  array<string, mixed>  $projectConfig
     */
    private function resolveBaseUrl(array $projectConfig): ?string
    {
        $projectBaseUrl = $projectConfig['api']['baseUrl'] ?? null;

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
