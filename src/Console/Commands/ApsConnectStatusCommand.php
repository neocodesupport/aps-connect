<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use Neocode\ApsConnect\Console\Commands\Concerns\LinksAppStationProject;
use Neocode\ApsConnect\Exceptions\MissingCredentialsException;
use Neocode\ApsConnect\Exceptions\PublisherRequestException;
use Neocode\ApsConnect\Http\PublisherClient;
use Neocode\ApsConnect\Support\ApsCliSession;
use Neocode\ApsConnect\Support\ProjectConfigReader;

/**
 * PHP counterpart of `aps status` (aps-cli/src/commands/status.ts): a
 * read-only view of this project's App Station/Registra link.
 *
 * No flag: local identity only, no network call. --remote adds the App
 * Station status and release count. --secrets adds where the api key and
 * signing secret come from — never their value — as aps-connect itself
 * resolves them, which differs from aps-cli for the api key (see
 * ProjectConfigReader: no REGISTRA_API_KEY override).
 */
class ApsConnectStatusCommand extends Command
{
    use LinksAppStationProject;

    /**
     * The command signature.
     */
    protected $signature = 'aps-connect:status
        {--remote : Add the App Station status and published release count (network call)}
        {--secrets : Add where the api key and signing secret come from (never their value)}';

    /**
     * The command description.
     */
    protected $description = 'Show this project\'s App Station link, read-only — like "aps status".';

    /**
     * Execute the console command.
     */
    public function handle(ApsCliSession $session, ProjectConfigReader $reader, HttpFactory $http): int
    {
        $this->components->twoColumnDetail('<fg=gray>Session</>');
        $this->components->twoColumnDetail('App Station', $this->sessionBaseUrl($session) ?? '(non connecté — exécutez "php artisan aps-connect:login")');

        try {
            $identity = $reader->projectIdentity();
        } catch (MissingCredentialsException) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=gray>Projet (appstation.conf.json)</>');
            $this->components->twoColumnDetail('Lien', '(non lié — exécutez "php artisan aps-connect:init")');

            return self::SUCCESS;
        }

        $config = $reader->projectConfig();
        $environment = $this->string($config, 'environment') ?? 'production';
        $appStation = $this->section($config, 'appstation');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Projet (appstation.conf.json)</>');
        $this->components->twoColumnDetail('Type', $identity->type);
        $this->components->twoColumnDetail('Environnement', $environment);

        if ($identity->isModule()) {
            $module = $this->section($config, 'module');
            $parent = $this->section($config, 'parentSoftware');
            $parentId = $appStation['parentSoftwareId'] ?? null;

            $this->components->twoColumnDetail('Module', "{$identity->name} (".($this->string($module, 'slug') ?? '?').", id {$identity->id})");
            $this->components->twoColumnDetail('Parent', ($this->string($parent, 'name') ?? '?').' (id '.(is_int($parentId) ? $parentId : '?').')');
            $this->components->twoColumnDetail('Token', $this->string($module, 'token') ?? '?');
        } else {
            $this->components->twoColumnDetail('Software', "{$identity->name} (id {$identity->id})");
            $this->components->twoColumnDetail('Token', $this->string($this->section($config, 'software'), 'token') ?? '?');
        }

        $this->components->twoColumnDetail('Registra', $this->string($this->section($config, 'api'), 'baseUrl') ?? '?');
        $fingerprint = $this->string($appStation, 'projectFingerprint');
        $this->components->twoColumnDetail('Fingerprint', $fingerprint !== null ? mb_substr($fingerprint, 0, 12).'…' : '?');

        if ($this->option('remote') && ! $this->showRemote($session, $identity->type, $identity->id, $http)) {
            return self::FAILURE;
        }

        if ($this->option('secrets')) {
            $this->showSecretSources($reader, $environment);
        }

        return self::SUCCESS;
    }

    private function showRemote(ApsCliSession $session, string $type, int $id, HttpFactory $http): bool
    {
        $credentials = $this->resolveSession($session);

        if ($credentials === null) {
            return false;
        }

        $client = new PublisherClient($credentials, $http);
        $isModule = $type === 'module';

        try {
            $items = $isModule ? $client->listPackages() : $client->listSoftwares();
            $match = array_values(array_filter($items, static fn (array $item): bool => ($item['id'] ?? null) === $id))[0] ?? null;

            if ($match === null) {
                $this->components->error(($isModule ? 'Module' : 'Software').' introuvable sur votre compte App Station.');

                return false;
            }

            $releases = $isModule ? $client->listPackageReleases($id) : $client->listSoftwareReleases($id);
        } catch (PublisherRequestException $e) {
            $this->components->error($e->getMessage());

            return false;
        }

        $active = count(array_filter($releases, static fn (array $release): bool => ($release['is_yanked'] ?? false) !== true));
        $total = count($releases);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>App Station (--remote)</>');
        $this->components->twoColumnDetail('Statut', is_string($match['status'] ?? null) ? $match['status'] : '?');

        $active > 0
            ? $this->components->info("Release(s) publiée(s) : {$active} ({$total} au total, yanked incluses).")
            : $this->components->warn("Aucune release publiée pour le moment ({$total} au total, yanked incluses).");

        $this->line('  Rappel : une fois soumis en review, lancez "php artisan aps-connect:fetch-key --production" pour basculer en production et rebuilder avant publication.');

        return true;
    }

    private function showSecretSources(ProjectConfigReader $reader, string $environment): void
    {
        $local = $reader->localConfig();
        $hasApiKey = $this->string($this->section($local, 'auth'), 'apiKey') !== null;
        $hasSigningSecret = $this->string($local, 'signingSecret') !== null;
        $envSigningSecret = trim((string) getenv('APS_SIGNING_SECRET')) !== '';

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Secrets (--secrets) — source uniquement, jamais la valeur</>');
        $this->components->twoColumnDetail('Clé API', $hasApiKey
            ? 'appstation.conf.local.json'
            : 'introuvable (appstation.conf.local.json — seule source lue par aps-connect)');
        $this->components->twoColumnDetail('signingSecret', match (true) {
            $envSigningSecret => "variable d'environnement APS_SIGNING_SECRET",
            $hasSigningSecret => 'appstation.conf.local.json',
            default => 'introuvable (ni APS_SIGNING_SECRET, ni appstation.conf.local.json)',
        });

        $localEnvironment = $this->string($local, 'environment');

        if ($local !== [] && $localEnvironment !== $environment) {
            $this->components->warn(
                "appstation.conf.local.json contient actuellement des identifiants \"{$localEnvironment}\", pas \"{$environment}\" — probablement un échec partiel. Exécutez \"php artisan aps-connect:fetch-key\" pour réaligner sur \"{$environment}\".",
            );
        }
    }

    /**
     * Where the publisher session would come from, without failing: APS_TOKEN
     * with APPSTATION_BASE_URL, else the stored `aps login` session.
     */
    private function sessionBaseUrl(ApsCliSession $session): ?string
    {
        $envBaseUrl = trim((string) getenv('APPSTATION_BASE_URL'));

        if (trim((string) getenv('APS_TOKEN')) !== '' && $envBaseUrl !== '') {
            return rtrim($envBaseUrl, '/').' (APS_TOKEN)';
        }

        return $session->credentials()?->baseUrl;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function section(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(array $data, string $key): ?string
    {
        return is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
    }
}
