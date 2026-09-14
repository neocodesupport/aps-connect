<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands;

use Illuminate\Console\Command;
use Neocode\ApsConnect\ApsConnect;
use Neocode\ApsConnect\Data\AppStationCredentials;
use Neocode\ApsConnect\Exceptions\ApsConnectException;
use Neocode\ApsConnect\Exceptions\InvalidApiKeyException;
use Neocode\ApsConnect\Exceptions\LicenceNotFoundException;
use Neocode\ApsConnect\Exceptions\MissingCredentialsException;

class ApsConnectDoctorCommand extends Command
{
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
            $identity = $apsConnect->me();
        } catch (MissingCredentialsException $e) {
            $this->components->error("Configuration incomplète : {$e->getMessage()}");

            return self::FAILURE;
        } catch (InvalidApiKeyException $e) {
            $this->components->error("Registra a rejeté la clé API configurée : {$e->getMessage()}");

            return self::FAILURE;
        } catch (ApsConnectException $e) {
            $this->components->error("Impossible de joindre Registra : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Connecté à Registra en tant que \"{$identity->name}\" (environnement : {$identity->environment}).");

        if ($identity->hasPreviousApiKeyGracePeriod) {
            $this->components->warn(
                "Une ancienne clé API est encore acceptée jusqu'au {$identity->apiKeyPreviousExpiresAt}. Pensez à faire tourner la clé côté application.",
            );
        }

        try {
            $apsConnect->verifyLicence('APS-CONNECT-DOCTOR-PROBE');
            $this->components->info('La vérification de licence répond correctement (clé acceptée).');
        } catch (LicenceNotFoundException) {
            $this->components->info('La clé API est acceptée par Registra (licence de test introuvable, comme attendu).');
        } catch (InvalidApiKeyException $e) {
            $this->components->error("La clé API a été rejetée lors du test de vérification : {$e->getMessage()}");

            return self::FAILURE;
        }

        // No live probe against App Station here: `instances/register` (the
        // only endpoint authenticated with this same product key) creates a
        // real SoftwareInstance on every call — unlike Registra's
        // verifyLicence(), it has no side-effect-free equivalent to call
        // with a throwaway probe value.
        $appStationCredentials = app(AppStationCredentials::class);
        $this->components->info("App Station : URL résolue sur \"{$appStationCredentials->baseUrl}\" (même clé API que Registra ci-dessus).");

        return self::SUCCESS;
    }
}
