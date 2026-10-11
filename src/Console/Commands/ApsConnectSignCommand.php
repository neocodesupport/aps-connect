<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Neocode\ApsConnect\Console\Commands\Concerns\PromptsMissingOptions;
use Neocode\ApsConnect\Support\ManifestSigner;
use Neocode\ApsConnect\Support\ProjectConfigReader;

/**
 * PHP counterpart of `aps sign` (aps-cli/src/commands/sign.ts): writes a
 * manifest, signed with HMAC-SHA256, proving the link between this
 * repository, its App Station software/module and its Registra environment.
 * It carries the api key's SHA-256 only, never the key.
 */
class ApsConnectSignCommand extends Command
{
    use PromptsMissingOptions;

    private const string DEFAULT_OUTPUT = 'appstation.manifest.signed.json';

    /**
     * The command signature.
     */
    protected $signature = 'aps-connect:sign
        {--output='.self::DEFAULT_OUTPUT.' : Where to write the signed manifest (relative to the project root)}';

    /**
     * The command description.
     */
    protected $description = 'Write a signed manifest proving this repository\'s App Station/Registra link — like "aps sign".';

    /**
     * Execute the console command.
     */
    public function handle(ProjectConfigReader $reader, ManifestSigner $signer): int
    {
        $config = $reader->projectConfig();

        if ($config === []) {
            $this->components->error('appstation.conf.json introuvable. Exécutez "php artisan aps-connect:init".');

            return self::FAILURE;
        }

        $local = $reader->localConfig();

        if ($local === []) {
            $this->components->error('appstation.conf.local.json introuvable. Exécutez "php artisan aps-connect:init".');

            return self::FAILURE;
        }

        $environment = $config['environment'] ?? null;
        $localEnvironment = $local['environment'] ?? null;

        if ($localEnvironment !== $environment) {
            $this->components->error(sprintf(
                'appstation.conf.local.json contient actuellement des identifiants "%s", pas "%s" — signer maintenant produirait un manifest incohérent. Exécutez "php artisan aps-connect:fetch-key" pour réaligner appstation.conf.local.json.',
                is_string($localEnvironment) ? $localEnvironment : '?',
                is_string($environment) ? $environment : '?',
            ));

            return self::FAILURE;
        }

        $apiKey = is_array($local['auth'] ?? null) ? ($local['auth']['apiKey'] ?? null) : null;
        $signingSecret = self::resolveSigningSecret($local);

        if (! is_string($apiKey) || $apiKey === '' || $signingSecret === null) {
            $this->components->error('Clé API ou signingSecret introuvable (ni APS_SIGNING_SECRET, ni appstation.conf.local.json). Exécutez "php artisan aps-connect:init".');

            return self::FAILURE;
        }

        $signed = $signer->sign($signer->buildPayload($config, $apiKey, new DateTimeImmutable), $signingSecret);

        $output = $this->stringOption('output') ?? self::DEFAULT_OUTPUT;
        $path = $this->isAbsolutePath($output) ? $output : $this->laravel->basePath($output);

        file_put_contents($path, str_replace('    ', '  ', json_encode($signed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))."\n");

        $this->components->info("Manifest signé écrit : {$output}");
        $this->line('  Prochaine étape : soumettez ce fichier à App Station (dashboard éditeur ou review).');
        $this->line("  Vérifiable localement avec \"php artisan aps-connect:verify {$output}\".");

        return self::SUCCESS;
    }

    /**
     * APS_SIGNING_SECRET first (CI, or verifying outside the repository),
     * else appstation.conf.local.json — aps-cli's resolveSigningSecret().
     *
     * @param  array<string, mixed>  $local
     */
    public static function resolveSigningSecret(array $local): ?string
    {
        $fromEnv = getenv('APS_SIGNING_SECRET');

        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return $fromEnv;
        }

        $fromFile = $local['signingSecret'] ?? null;

        return is_string($fromFile) && $fromFile !== '' ? $fromFile : null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
