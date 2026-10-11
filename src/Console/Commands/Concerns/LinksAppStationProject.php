<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands\Concerns;

use Closure;
use Neocode\ApsConnect\Data\PublisherCredentials;
use Neocode\ApsConnect\Exceptions\ProjectAlreadyLinkedException;
use Neocode\ApsConnect\Support\ApsCliSession;
use Neocode\ApsConnect\Support\ProjectConfigWriter;
use RuntimeException;

/**
 * What `aps-connect:init` and `aps-connect:fetch-key` share, mirroring the
 * helpers aps-cli's fetch-key reuses from its init: the publisher session,
 * the link-with-409-retry flow, and the appstation.conf.json layout.
 * Commands using it also use PromptsMissingOptions (confirmIfInteractive()).
 */
trait LinksAppStationProject
{
    /**
     * aps-cli's resolveSession(): APS_TOKEN (which then needs
     * APPSTATION_BASE_URL — there is no project config to take it from yet),
     * else the `aps login` session stored on this machine.
     */
    private function resolveSession(ApsCliSession $session): ?PublisherCredentials
    {
        $envToken = trim((string) getenv('APS_TOKEN'));

        if ($envToken !== '') {
            $envBaseUrl = trim((string) getenv('APPSTATION_BASE_URL'));

            if ($envBaseUrl === '') {
                $this->components->error('APS_TOKEN est défini mais APPSTATION_BASE_URL est manquant.');

                return null;
            }

            return new PublisherCredentials($envToken, rtrim($envBaseUrl, '/'));
        }

        $stored = $session->credentials();

        if ($stored === null) {
            $this->components->error('Vous devez être connecté : exécutez "aps login" (aps-cli), ou définissez APS_TOKEN et APPSTATION_BASE_URL.');
        }

        return $stored;
    }

    /**
     * Links, warns about a Registra dev sync error, and on a 409 (already
     * linked to another repository) offers to force the link.
     *
     * @param  Closure(bool): array{data: array<string, mixed>, registra_dev_sync_error: ?string}  $link
     * @return array<string, mixed>
     */
    private function linkWithRetry(string $label, Closure $link, bool $force): array
    {
        try {
            $result = $this->withSpinner("Liaison du projet au {$label} App Station...", fn () => $link($force));
        } catch (ProjectAlreadyLinkedException $e) {
            if ($force) {
                throw $e;
            }

            if (! $this->input->isInteractive() || ! $this->confirmIfInteractive("Ce {$label} est déjà lié à un autre dépôt local. Forcer la liaison ?")) {
                throw new RuntimeException("Ce {$label} est déjà lié à un autre dépôt local. Relancez avec --force-link pour forcer la liaison.");
            }

            $result = $this->withSpinner('Liaison forcée du projet...', fn () => $link(true));
        }

        if ($result['registra_dev_sync_error'] !== null) {
            $this->components->warn("Sync Registra dev : {$result['registra_dev_sync_error']}");
        }

        return $result['data'];
    }

    /**
     * Same shape as aps-cli's buildSoftwareConfig()/buildModuleConfig().
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $registra
     * @return array<string, mixed>
     */
    private function buildProjectConfig(string $type, array $response, array $registra, string $appStationBaseUrl, string $fingerprint, ?string $stack, int $id): array
    {
        $common = [
            '$schema' => 'https://appstation.dev/schemas/appstation.conf.v1.json',
            'version' => 1,
            'type' => $type,
            'environment' => $registra['environment'] ?? null,
        ];

        $tail = [
            'api' => ['baseUrl' => $registra['baseUrl']],
            'auth' => ['apiKeySource' => 'env', 'apiKeyEnvVar' => ProjectConfigWriter::API_KEY_ENV_VAR],
            'integration' => ['detectedStack' => $stack],
        ];

        if ($type === 'module') {
            $module = is_array($response['module'] ?? null) ? $response['module'] : [];
            $parent = is_array($response['parentSoftware'] ?? null) ? $response['parentSoftware'] : [];

            return [
                ...$common,
                'module' => ['token' => $module['token'] ?? null, 'slug' => $module['slug'] ?? null, 'name' => $module['name'] ?? null],
                'parentSoftware' => ['token' => $parent['token'] ?? null, 'name' => $parent['name'] ?? null],
                ...$tail,
                'appstation' => [
                    'baseUrl' => $appStationBaseUrl,
                    'packageId' => $id,
                    'parentSoftwareId' => $parent['id'] ?? null,
                    'projectFingerprint' => $fingerprint,
                ],
            ];
        }

        $software = is_array($response['software'] ?? null) ? $response['software'] : [];

        return [
            ...$common,
            'software' => ['token' => $software['token'] ?? null, 'name' => $software['name'] ?? null],
            ...$tail,
            'appstation' => ['baseUrl' => $appStationBaseUrl, 'softwareId' => $id, 'projectFingerprint' => $fingerprint],
        ];
    }
}
