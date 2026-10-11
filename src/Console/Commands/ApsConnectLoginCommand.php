<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use Neocode\ApsConnect\Console\Commands\Concerns\PromptsMissingOptions;
use Neocode\ApsConnect\Data\PublisherCredentials;
use Neocode\ApsConnect\Exceptions\PublisherRequestException;
use Neocode\ApsConnect\Http\PublisherClient;
use Neocode\ApsConnect\Support\ApsCliSession;
use RuntimeException;

/**
 * PHP counterpart of `aps login --token` (aps-cli/src/commands/login.ts):
 * checks a publisher token against App Station, then stores it in the same
 * credentials.json as aps-cli, so one login serves both tools. The browser
 * login flow stays in aps-cli; here the token is generated in App Station
 * (Paramètres → CLI) and pasted.
 */
class ApsConnectLoginCommand extends Command
{
    use PromptsMissingOptions;

    private const string PRODUCTION_BASE_URL = 'https://app-station.neocode.ci';

    private const string DEV_BASE_URL = 'https://app-station.test';

    /**
     * The command signature.
     */
    protected $signature = 'aps-connect:login
        {--token= : Publisher token, generated in App Station → Paramètres → CLI (default: APS_TOKEN, else asked)}
        {--base-url= : App Station url (default: APPSTATION_BASE_URL, else https://app-station.neocode.ci)}
        {--dev : Use https://app-station.test unless --base-url/APPSTATION_BASE_URL is set}';

    /**
     * The command description.
     */
    protected $description = 'Log in to App Station with a publisher token, shared with the aps CLI — like "aps login --token".';

    /**
     * Execute the console command.
     */
    public function handle(ApsCliSession $session, HttpFactory $http): int
    {
        $baseUrl = $this->resolveBaseUrl();

        $token = $this->stringOption('token')
            ?? (trim((string) getenv('APS_TOKEN')) ?: null)
            ?? $this->secretIfInteractive("Token éditeur (App Station → Paramètres → CLI, sur {$baseUrl})");

        $token = $token !== null ? trim($token) : null;

        if ($token === null || $token === '') {
            $this->components->error('Aucun token : passez --token, définissez APS_TOKEN, ou générez-en un dans App Station → Paramètres → CLI.');

            return self::FAILURE;
        }

        $credentials = new PublisherCredentials($token, $baseUrl);

        try {
            $this->withSpinner("Vérification du token auprès d'App Station...", fn () => (new PublisherClient($credentials, $http))->listSoftwares());
        } catch (PublisherRequestException $e) {
            $this->components->error("Impossible de valider le token auprès d'App Station ({$baseUrl}) : {$e->getMessage()}");

            return self::FAILURE;
        }

        try {
            $session->store($credentials);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Connecté à {$baseUrl}.");
        $this->line('  Prochaine étape : php artisan aps-connect:init — lier ou créer un software/module App Station.');

        return self::SUCCESS;
    }

    private function resolveBaseUrl(): string
    {
        $explicit = $this->stringOption('base-url') ?? (trim((string) getenv('APPSTATION_BASE_URL')) ?: null);

        if ($explicit !== null) {
            return rtrim(trim($explicit), '/');
        }

        return $this->option('dev') ? self::DEV_BASE_URL : self::PRODUCTION_BASE_URL;
    }
}
