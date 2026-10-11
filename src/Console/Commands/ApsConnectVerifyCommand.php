<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Neocode\ApsConnect\Console\Commands\Concerns\PromptsMissingOptions;
use Neocode\ApsConnect\Support\ManifestSigner;
use Neocode\ApsConnect\Support\ProjectConfigReader;

/**
 * PHP counterpart of `aps verify <file>` (aps-cli/src/commands/verify.ts):
 * checks a signed manifest's schema, HMAC-SHA256 signature and freshness
 * (30 days). Needs the signing secret it was signed with — this project's
 * appstation.conf.local.json, or APS_SIGNING_SECRET.
 */
class ApsConnectVerifyCommand extends Command
{
    use PromptsMissingOptions;

    /**
     * The command signature.
     */
    protected $signature = 'aps-connect:verify
        {file : The signed manifest to verify}';

    /**
     * The command description.
     */
    protected $description = 'Verify a signed manifest (schema, HMAC signature, freshness) — like "aps verify".';

    /**
     * Execute the console command.
     */
    public function handle(ProjectConfigReader $reader, ManifestSigner $signer): int
    {
        $file = $this->stringArgument('file') ?? '';

        if (! is_file($file)) {
            $this->components->error("Fichier introuvable : {$file}");

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (! is_array($decoded)) {
            $this->components->error("JSON invalide : {$file}");

            return self::FAILURE;
        }

        $payload = $decoded['payload'] ?? null;
        $signature = $decoded['signature'] ?? null;

        if (! is_array($payload) || ! is_string($signature)) {
            $this->components->error("Structure de manifest invalide (attendu { payload, signature }) : {$file}");

            return self::FAILURE;
        }

        $signingSecret = ApsConnectSignCommand::resolveSigningSecret($reader->localConfig());

        if ($signingSecret === null) {
            $this->components->error('signingSecret introuvable (ni APS_SIGNING_SECRET, ni appstation.conf.local.json). Exécutez "php artisan aps-connect:init".');

            return self::FAILURE;
        }

        $errors = $signer->verify($payload, $signature, $signingSecret, new DateTimeImmutable);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->components->error($error);
            }

            $this->components->error('Manifest invalide.');

            return self::FAILURE;
        }

        $this->components->info("Manifest valide : {$file}");

        return self::SUCCESS;
    }
}
