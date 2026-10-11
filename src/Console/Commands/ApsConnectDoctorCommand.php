<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands;

use Illuminate\Console\Command;
use Neocode\ApsConnect\ApsConnect;
use Neocode\ApsConnect\Data\AppStationCredentials;
use Neocode\ApsConnect\Data\RegistraCredentials;
use Neocode\ApsConnect\Data\SoftwareIdentity;
use Neocode\ApsConnect\Exceptions\ApsConnectException;
use Neocode\ApsConnect\Exceptions\InvalidApiKeyException;
use Neocode\ApsConnect\Exceptions\LicenceNotFoundException;
use Neocode\ApsConnect\Exceptions\MissingCredentialsException;

class ApsConnectDoctorCommand extends Command
{
    /**
     * A reserved Registra dev licence key (App\Support\DevLicenceKey):
     * always "active" for a development software, whatever its key mask,
     * and never persisted. The sandbox only identifies the api key for such
     * a key — an arbitrary one is rejected before the key is even checked.
     */
    public const string DEV_PROBE_LICENCE_KEY = 'DEV42-OK000-42424';

    /**
     * A key guaranteed not to exist, for production: Registra checks the api
     * key first, so "licence not found" proves the key is accepted.
     */
    private const string PRODUCTION_PROBE_LICENCE_KEY = 'APS-CONNECT-DOCTOR-PROBE';

    /**
     * The command signature.
     */
    protected $signature = 'aps-connect:doctor';

    /**
     * The command description.
     */
    protected $description = 'Diagnose the Registra connection (verifies the API key) and report the resolved App Station configuration.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $apsConnect = app(ApsConnect::class);
            $development = app(RegistraCredentials::class)->environment === 'development';
        } catch (MissingCredentialsException $e) {
            $this->components->error("Configuration incomplète : {$e->getMessage()}");

            return self::FAILURE;
        }

        $identityChecked = $this->checkIdentity($apsConnect, $development);

        if ($identityChecked === false) {
            return self::FAILURE;
        }

        if (! ($development ? $this->probeSandbox($apsConnect) : $this->probeProduction($apsConnect))) {
            return self::FAILURE;
        }

        // No live probe against App Station here: `instances/register` (the
        // only endpoint authenticated with this same product key) creates a
        // real SoftwareInstance on every call — unlike Registra's
        // verifyLicence(), it has no side-effect-free equivalent to call
        // with a throwaway probe value.
        try {
            $appStationCredentials = app(AppStationCredentials::class);
        } catch (MissingCredentialsException $e) {
            $this->components->error("Configuration incomplète : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("App Station : URL résolue sur \"{$appStationCredentials->baseUrl}\" (même clé API que Registra ci-dessus).");

        return self::SUCCESS;
    }

    /**
     * `software/me` has no sandbox equivalent: in development, App Station
     * only registers the software on Registra once it is submitted for
     * review, so a 401 there is expected for a draft — the sandbox probe
     * that follows is what proves the key then.
     *
     * @return bool|null false on failure, null when skipped (development, not on Registra yet)
     */
    private function checkIdentity(ApsConnect $apsConnect, bool $development): ?bool
    {
        try {
            $identity = $apsConnect->me();
        } catch (InvalidApiKeyException $e) {
            if ($development) {
                $this->components->warn(
                    'Registra ne reconnaît pas cette clé de développement (software/me). Normal pour un brouillon : App Station n\'enregistre le software sur Registra qu\'à la soumission en review. '
                    .'S\'il a déjà été soumis, la clé dev de Registra n\'est plus celle d\'App Station : seuls les appels sandbox (verifyLicence, verifyModuleLicence, subscribe) fonctionneront. '
                    .'La clé elle-même est vérifiée via le sandbox ci-dessous.',
                );

                return null;
            }

            $this->components->error("Registra a rejeté la clé API configurée : {$e->getMessage()}");

            return false;
        } catch (ApsConnectException $e) {
            $this->components->error("Impossible de joindre Registra : {$e->getMessage()}");

            return false;
        }

        $this->reportIdentity($identity);

        return true;
    }

    private function reportIdentity(SoftwareIdentity $identity): void
    {
        $this->components->info("Connecté à Registra en tant que \"{$identity->name}\" (environnement : {$identity->environment}).");

        if ($identity->hasPreviousApiKeyGracePeriod) {
            $this->components->warn(
                "Une ancienne clé API est encore acceptée jusqu'au {$identity->apiKeyPreviousExpiresAt}. Pensez à faire tourner la clé côté application.",
            );
        }
    }

    private function probeProduction(ApsConnect $apsConnect): bool
    {
        try {
            $apsConnect->verifyLicence(self::PRODUCTION_PROBE_LICENCE_KEY);
            $this->components->info('La vérification de licence répond correctement (clé acceptée).');
        } catch (LicenceNotFoundException) {
            $this->components->info('La clé API est acceptée par Registra (licence de test introuvable, comme attendu).');
        } catch (InvalidApiKeyException $e) {
            $this->components->error("La clé API a été rejetée lors du test de vérification : {$e->getMessage()}");

            return false;
        } catch (ApsConnectException $e) {
            $this->components->error("Impossible de joindre Registra : {$e->getMessage()}");

            return false;
        }

        return true;
    }

    /**
     * Verifies the reserved dev key through Registra's sandbox, which
     * identifies the api key with App Station — so it works from the very
     * first draft, and fails on a wrong key.
     */
    private function probeSandbox(ApsConnect $apsConnect): bool
    {
        try {
            $status = $apsConnect->verifyLicence(self::DEV_PROBE_LICENCE_KEY);
        } catch (InvalidApiKeyException $e) {
            $this->components->error("La clé API de développement a été rejetée par le sandbox Registra : {$e->getMessage()}");

            return false;
        } catch (LicenceNotFoundException) {
            $this->components->error(
                'Le sandbox Registra ne reconnaît pas ce software comme étant en développement (clé API de production ?). '
                .'Exécutez "php artisan aps-connect:fetch-key" pour récupérer la clé de l\'environnement du projet.',
            );

            return false;
        } catch (ApsConnectException $e) {
            $this->components->error("Impossible de joindre le sandbox Registra : {$e->getMessage()}");

            return false;
        }

        if (! $status->active) {
            $this->components->error('Le sandbox Registra a répondu, mais la licence de test « active » n\'est pas utilisable ('.($status->reason ?? 'raison inconnue').').');

            return false;
        }

        $this->components->info('Sandbox Registra : clé API de développement acceptée (licence de test '.self::DEV_PROBE_LICENCE_KEY.' active).');
        $this->line('  Licences de test : DEV42-OK000-42424 (active), DEV42-BLOCK-42424, DEV42-PEND0-42424, DEV42-ADLPS-42424, DEV42-EXPIR-42424, DEV42-CINAC-42424.');

        return true;
    }
}
