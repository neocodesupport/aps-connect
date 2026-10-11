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
use Neocode\ApsConnect\Support\ProjectConfigWriter;
use Neocode\ApsConnect\Support\ProjectInspector;
use RuntimeException;

/**
 * PHP counterpart of `aps fetch-key` (aps-cli/src/commands/fetch-key.ts).
 *
 * Without --production: re-issues the Registra key and signing secret of the
 * environment appstation.conf.json already names (after a rotation done on
 * Registra's side) into appstation.conf.local.json.
 *
 * With --production: switches the project to production in one go — both
 * files move to "production" together, never one without the other. The
 * production key is issued right away whatever the status, but Registra
 * only knows it once the software/module is submitted for review. Going
 * back to development is `aps-connect:init --env=development`.
 */
class ApsConnectFetchKeyCommand extends Command
{
    use LinksAppStationProject;

    private const array REGISTRA_SUBMITTED_STATUSES = ['pending_review', 'published'];

    /**
     * The command signature.
     */
    protected $signature = 'aps-connect:fetch-key
        {--production : Switch the project to production and fetch its production key}';

    /**
     * The command description.
     */
    protected $description = 'Refresh the local Registra key, or switch the project to production with --production — like "aps fetch-key".';

    /**
     * Execute the console command.
     */
    public function handle(
        ApsCliSession $session,
        ProjectConfigReader $reader,
        ProjectConfigWriter $writer,
        ProjectInspector $inspector,
        HttpFactory $http,
    ): int {
        try {
            $identity = $reader->projectIdentity();
        } catch (MissingCredentialsException) {
            $this->components->error('appstation.conf.json introuvable ou incomplet. Exécutez "php artisan aps-connect:init".');

            return self::FAILURE;
        }

        $config = $reader->projectConfig();
        $currentEnvironment = is_string($config['environment'] ?? null) ? $config['environment'] : 'production';
        $appStation = is_array($config['appstation'] ?? null) ? $config['appstation'] : [];
        $fingerprint = $appStation['projectFingerprint'] ?? null;

        if (! is_string($fingerprint) || $fingerprint === '') {
            $this->components->error('appstation.conf.json n\'a pas de `appstation.projectFingerprint`. Exécutez "php artisan aps-connect:init".');

            return self::FAILURE;
        }

        $production = (bool) $this->option('production');

        if ($production && $currentEnvironment === 'production') {
            $this->components->error('Ce projet est déjà configuré en production. Utilisez "php artisan aps-connect:fetch-key" (sans --production) pour rafraîchir la clé.');

            return self::FAILURE;
        }

        $targetEnvironment = $production ? 'production' : $currentEnvironment;

        $credentials = $this->resolveSession($session);

        if ($credentials === null) {
            return self::FAILURE;
        }

        $client = new PublisherClient($credentials, $http);

        try {
            $linked = $identity->isModule()
                ? $this->linkWithRetry('module', fn (bool $force) => $client->linkPackage($identity->id, $fingerprint, $force), false)
                : $this->linkWithRetry('software', fn (bool $force) => $client->linkSoftware($identity->id, $fingerprint, $force), false);

            $this->components->info($production ? 'Récupération de la clé Registra production...' : 'Récupération de la clé Registra courante...');

            $response = $client->registraInit($identity->type, $identity->id, $targetEnvironment, $fingerprint);
        } catch (PublisherRequestException|RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $registra = is_array($response['registra'] ?? null) ? $response['registra'] : [];
        $apiKey = $registra['apiKey'] ?? null;
        $signingSecret = $response['signingSecret'] ?? null;

        if (! is_string($apiKey) || ! is_string($registra['baseUrl'] ?? null) || ! is_string($signingSecret)) {
            $this->components->error('Réponse inattendue d\'App Station (registra/init) : clé API, URL Registra ou signingSecret manquant.');

            return self::FAILURE;
        }

        if ($production) {
            $integration = is_array($config['integration'] ?? null) ? $config['integration'] : [];
            $stack = is_string($integration['detectedStack'] ?? null) ? $integration['detectedStack'] : null;

            $writer->writeProjectConfig($this->buildProjectConfig(
                $identity->type, $response, $registra, $credentials->baseUrl, $fingerprint, $stack, $identity->id,
            ));
        }

        $writer->writeLocalConfig($targetEnvironment, $apiKey, $signingSecret);

        if (! $production) {
            $this->components->info("appstation.conf.local.json mis à jour avec la clé Registra courante ({$targetEnvironment}).");
            $this->line('  Prochaine étape : php artisan aps-connect:doctor — vérifier que la nouvelle clé fonctionne.');

            return self::SUCCESS;
        }

        $this->components->info('appstation.conf.json et appstation.conf.local.json basculés vers production.');

        if ($inspector->isTrackedByGit('appstation.conf.local.json')) {
            $this->components->error(
                'appstation.conf.local.json est suivi par git (probablement commité avant son ajout au .gitignore) : signingSecret finira dans l\'historique et chez quiconque clone ce dépôt. Exécutez "git rm --cached appstation.conf.local.json", commitez, puis envisagez une rotation de la clé.',
            );
        }

        $status = is_string($linked['status'] ?? null) ? $linked['status'] : '';

        if (! in_array($status, self::REGISTRA_SUBMITTED_STATUSES, true)) {
            $this->components->warn(
                "Statut App Station actuel : \"{$status}\" — cette clé prod est générée localement mais ne sera active côté Registra qu'après soumission à la validation (\"pending_review\" ou \"published\").",
            );
        }

        $this->newLine();
        $this->line('  Prochaines étapes :');
        $this->line('  - Rebuildez votre logiciel avec la nouvelle clé (ne livrez jamais appstation.conf.local.json : il contient aussi signingSecret).');
        $this->line('  - php artisan aps-connect:release --publish --release-version=<version> — publiez ce build, pas celui qui a servi à la review.');
        $this->line('  - php artisan aps-connect:doctor — vérifier que la clé prod fonctionne.');
        $this->line('  - Pour revenir en développement : php artisan aps-connect:init --env=development.');

        return self::SUCCESS;
    }
}
