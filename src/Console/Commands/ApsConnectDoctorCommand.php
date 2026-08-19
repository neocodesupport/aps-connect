<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Console\Commands;

use ApsConnect\ApsConnect\ApsConnect;
use ApsConnect\ApsConnect\Exceptions\ApsConnectException;
use ApsConnect\ApsConnect\Exceptions\InvalidApiKeyException;
use ApsConnect\ApsConnect\Exceptions\LicenceNotFoundException;
use Illuminate\Console\Command;

class ApsConnectDoctorCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'aps-connect:doctor';

    /**
     * The command description.
     */
    protected $description = 'Diagnose the Registra connection: resolves credentials and confirms the API key is accepted.';

    /**
     * Execute the console command.
     */
    public function handle(ApsConnect $apsConnect): int
    {
        try {
            $identity = $apsConnect->me();
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

        return self::SUCCESS;
    }
}
