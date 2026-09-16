<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Support;

use Neocode\ApsConnect\Data\AppStationCredentials;
use Neocode\ApsConnect\Data\ProjectIdentity;
use Neocode\ApsConnect\Data\RegistraCredentials;
use Neocode\ApsConnect\Exceptions\MissingCredentialsException;

/**
 * Resolves Registra credentials the same way the `aps` CLI's own project
 * files are laid out. appstation.conf.json (versioned, publisher-controlled)
 * and appstation.conf.local.json (gitignored, local/deployment-controlled)
 * are the only two sources: no Laravel config or env var ever overrides or
 * fills in for either. `api.baseUrl` and `environment` come exclusively from
 * appstation.conf.json; the api key comes exclusively from
 * appstation.conf.local.json's `auth.apiKey` — see credentials(). A missing
 * or empty value in either file throws MissingCredentialsException rather
 * than silently falling back to something a deployer could edit.
 */
final class ProjectConfigReader
{
    /** @var array<string, array<string, mixed>> */
    private array $jsonFileCache = [];

    public function __construct(private readonly string $basePath) {}

    public function credentials(): RegistraCredentials
    {
        $projectConfig = $this->readJsonFile($this->basePath.'/appstation.conf.json');
        $localConfig = $this->readJsonFile($this->basePath.'/appstation.conf.local.json');

        $environment = $this->resolveEnvironment($projectConfig);

        $apiKey = $localConfig['auth']['apiKey'] ?? null;
        $baseUrl = $this->resolveBaseUrl($projectConfig);

        if (! is_string($apiKey) || $apiKey === '') {
            throw new MissingCredentialsException(
                'No Registra API key configured: appstation.conf.local.json has no `auth.apiKey`. '.
                'Run `aps init` to write it.',
            );
        }

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new MissingCredentialsException(
                'No Registra base URL configured: appstation.conf.json has no `api.baseUrl`. '.
                'Run `aps init` (or `aps promote`) to write it.',
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
     * duplicate ProjectConfigReader::credentials()'s file resolution.
     *
     * Like Registra's `api.baseUrl`, `appstation.baseUrl` has NO config/env
     * fallback: it determines which server distribution/registration calls
     * are sent to, so a missing value fails loudly instead of silently
     * falling back to a value a deployer could edit.
     */
    public function appStationCredentials(RegistraCredentials $registraCredentials): AppStationCredentials
    {
        return new AppStationCredentials($registraCredentials->apiKey, $this->appStationBaseUrl());
    }

    /**
     * The bare App Station base url on its own, with no Registra api key
     * attached — used by ApsConnectReleasePublishCommand, which authenticates
     * with a publisher session token instead and has no reason to resolve
     * (or require) the unrelated Registra runtime api key just to find this
     * url. Extracted out of appStationCredentials() above, which still needs
     * both.
     */
    public function appStationBaseUrl(): string
    {
        $projectConfig = $this->readJsonFile($this->basePath.'/appstation.conf.json');

        $baseUrl = $this->resolveNestedBaseUrl($projectConfig, 'appstation');

        if ($baseUrl === null) {
            throw new MissingCredentialsException(
                'No App Station base URL configured: appstation.conf.json has no `appstation.baseUrl`. '.
                'Run `aps init` (or `aps promote`) to write it.',
            );
        }

        return $baseUrl;
    }

    /**
     * Which App Station software/module this project is linked to — read by
     * ApsConnectReleasePublishCommand to pick `publisher/softwares/{id}` vs
     * `publisher/packages/{id}`. Unlike credentials()/appStationCredentials(),
     * this has no "trust anchor" concern (it doesn't decide which server is
     * contacted, only which resource id is targeted), but it still throws
     * MissingCredentialsException for a missing/malformed project file, for
     * the same "fail loudly, tell the user to run `aps init`" reason.
     */
    public function projectIdentity(): ProjectIdentity
    {
        $projectConfig = $this->readJsonFile($this->basePath.'/appstation.conf.json');

        $type = $projectConfig['type'] ?? null;

        if ($type === 'module') {
            $id = $projectConfig['appstation']['packageId'] ?? null;
            $name = $projectConfig['module']['name'] ?? null;
        } elseif ($type === 'software') {
            $id = $projectConfig['appstation']['softwareId'] ?? null;
            $name = $projectConfig['software']['name'] ?? null;
        } else {
            throw new MissingCredentialsException(
                'No project type configured: appstation.conf.json has no (or an invalid) `type`. '.
                'Run `aps init` to write it.',
            );
        }

        if (! is_int($id)) {
            throw new MissingCredentialsException(
                "No {$type} id configured in appstation.conf.json. Run `aps init` (or `aps promote`) to write it.",
            );
        }

        if (! is_string($name) || $name === '') {
            throw new MissingCredentialsException(
                "No {$type} name configured in appstation.conf.json. Run `aps init` to write it.",
            );
        }

        return new ProjectIdentity($type, $id, $name);
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
     * application could edit in their own .env. No project file (or no
     * `api.baseUrl` key in it) means no Registra base URL, full stop: there
     * is deliberately no config/env fallback to redirect verification calls
     * to instead.
     *
     * @param  array<string, mixed>  $projectConfig
     */
    private function resolveBaseUrl(array $projectConfig): ?string
    {
        return $this->resolveNestedBaseUrl($projectConfig, 'api');
    }

    /**
     * Reads `$projectConfig[$projectSection]['baseUrl']`, or null when the
     * project file is silent (or `$projectSection` isn't the expected object
     * shape) — deliberately no config/env fallback, see resolveBaseUrl() and
     * appStationCredentials().
     *
     * @param  array<string, mixed>  $projectConfig
     */
    private function resolveNestedBaseUrl(array $projectConfig, string $projectSection): ?string
    {
        $section = $projectConfig[$projectSection] ?? null;
        $projectBaseUrl = is_array($section) ? ($section['baseUrl'] ?? null) : null;

        return is_string($projectBaseUrl) && $projectBaseUrl !== '' ? $projectBaseUrl : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (array_key_exists($path, $this->jsonFileCache)) {
            return $this->jsonFileCache[$path];
        }

        return $this->jsonFileCache[$path] = $this->decodeJsonFile($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonFile(string $path): array
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
