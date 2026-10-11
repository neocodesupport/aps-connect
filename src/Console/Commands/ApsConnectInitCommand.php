<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use Neocode\ApsConnect\Console\Commands\Concerns\LinksAppStationProject;
use Neocode\ApsConnect\Console\Commands\Concerns\PromptsMissingOptions;
use Neocode\ApsConnect\Console\InitWizard;
use Neocode\ApsConnect\Exceptions\PublisherRequestException;
use Neocode\ApsConnect\Exceptions\PublisherValidationException;
use Neocode\ApsConnect\Http\PublisherClient;
use Neocode\ApsConnect\Support\ApsCliSession;
use Neocode\ApsConnect\Support\ProjectConfigWriter;
use Neocode\ApsConnect\Support\ProjectInspector;
use RuntimeException;

/**
 * PHP counterpart of `aps init` (aps-cli/src/commands/init.ts): links this
 * repository to an existing App Station software/module, or creates one,
 * then writes appstation.conf.json, appstation.conf.local.json (Registra api
 * key + signing secret, one environment) and the .gitignore block — the
 * same files, in the same layout, so either tool can pick up where the
 * other left off. Authenticates with the publisher session: APS_TOKEN +
 * APPSTATION_BASE_URL, else the `aps login` session on this machine.
 *
 * Interactively, every question goes through InitWizard (Ctrl+U goes back
 * without losing answers); otherwise everything comes from options.
 */
class ApsConnectInitCommand extends Command
{
    use LinksAppStationProject;
    use PromptsMissingOptions;

    /**
     * Below these statuses, a production key issued by `registra/init` is
     * not active on Registra yet — see printNextSteps().
     */
    private const array REGISTRA_SUBMITTED_STATUSES = ['pending_review', 'published'];

    /**
     * The command signature.
     */
    protected $signature = 'aps-connect:init
        {--link : Link this repository to an existing software/module}
        {--create : Create a new software/module on App Station, then link it}
        {--type= : software|module (default: software)}
        {--env= : development|production (default: development)}
        {--software-id= : App Station software id to link (--link, software)}
        {--package-id= : App Station module id to link (--link, module)}
        {--parent-software-id= : Parent software id (--create, module)}
        {--name= : Name (--create; defaults to package.json name or the directory name)}
        {--tagline= : Short description, max 255 characters (--create, software)}
        {--license-type= : free|freemium|paid|open_source|subscription (--create, software; default: freemium)}
        {--has-modules : The new software offers modules (--create, software; asked when interactive)}
        {--force-link : Re-link even if it is already linked to another repository}
        {--yes : Overwrite an existing appstation.conf.json without asking}';

    /**
     * The command description.
     */
    protected $description = 'Link this project to an App Station software/module (or create one) and write its Registra configuration — like "aps init".';

    /**
     * Execute the console command.
     */
    public function handle(ApsCliSession $session, ProjectConfigWriter $writer, ProjectInspector $inspector, HttpFactory $http): int
    {
        $environment = $this->stringOption('env') ?? 'development';

        if (! in_array($environment, ['development', 'production'], true)) {
            $this->components->error("--env invalide : \"{$environment}\" (attendu : development, production).");

            return self::FAILURE;
        }

        try {
            $presets = $this->presets();
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $credentials = $this->resolveSession($session);

        if ($credentials === null) {
            return self::FAILURE;
        }

        if ($writer->projectConfigExists() && ! $this->option('yes')) {
            if (! $this->input->isInteractive()) {
                $this->components->error('appstation.conf.json existe déjà. Relancez avec --yes pour l\'écraser.');

                return self::FAILURE;
            }

            if (! $this->confirmIfInteractive('appstation.conf.json existe déjà. Écraser ?')) {
                $this->components->info('Annulé. Utilisez "php artisan aps-connect:init --yes" pour écraser sans confirmation.');

                return self::SUCCESS;
            }
        }

        $client = new PublisherClient($credentials, $http);

        try {
            $answers = $this->input->isInteractive()
                ? (new InitWizard(fn () => $client->listSoftwares(), fn () => $client->listPackages()))->run($presets, $inspector->name())
                : $this->answersFromOptions($presets, $inspector->name());

            if ($answers === null) {
                $this->components->info('Annulé : rien n\'a été créé.');

                return self::SUCCESS;
            }

            $type = $answers['type'];
            $fingerprint = $inspector->fingerprint();

            $this->components->info("aps-connect:init — {$type} / {$answers['mode']} / {$environment}");

            $target = $this->linkOrCreate($client, $answers, $fingerprint);

            $response = $this->withSpinner('Récupération de la clé Registra...', fn () => $client->registraInit($type, (int) $target['id'], $environment, $fingerprint));
        } catch (PublisherValidationException $e) {
            $this->components->error('Validation refusée par App Station :');

            foreach ($e->errors as $field => $messages) {
                foreach ($messages as $message) {
                    $this->line("  - {$field}: {$message}");
                }
            }

            return self::FAILURE;
        } catch (PublisherRequestException|RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $registra = is_array($response['registra'] ?? null) ? $response['registra'] : [];
        $apiKey = $registra['apiKey'] ?? null;
        $registraBaseUrl = $registra['baseUrl'] ?? null;
        $signingSecret = $response['signingSecret'] ?? null;

        if (! is_string($apiKey) || ! is_string($registraBaseUrl) || ! is_string($signingSecret)) {
            $this->components->error('Réponse inattendue d\'App Station (registra/init) : clé API, URL Registra ou signingSecret manquant.');

            return self::FAILURE;
        }

        $writer->writeProjectConfig($this->buildProjectConfig(
            $type, $response, $registra, $credentials->baseUrl, $fingerprint, $inspector->stack(), (int) $target['id'],
        ));
        $writer->writeLocalConfig(is_string($registra['environment'] ?? null) ? $registra['environment'] : $environment, $apiKey, $signingSecret);
        $patched = $writer->ensureGitignorePatched();

        $this->components->info('appstation.conf.json écrit.');
        $this->components->info('appstation.conf.local.json écrit (secret, non versionné).');

        if ($patched) {
            $this->components->info('.gitignore mis à jour.');
        }

        $this->printNextSteps($environment, is_string($target['status'] ?? null) ? $target['status'] : '');

        return self::SUCCESS;
    }

    /**
     * What the options already answer, validated.
     *
     * @return array{type: ?string, mode: ?string, id: ?int, parent_id: ?int, name: ?string, tagline: ?string, license_type: ?string, has_modules: ?bool}
     *
     * @throws RuntimeException on an invalid option
     */
    private function presets(): array
    {
        $type = $this->stringOption('type');

        if ($type !== null && ! in_array($type, ['software', 'module'], true)) {
            throw new RuntimeException("--type invalide : \"{$type}\" (attendu : software, module).");
        }

        $licenseType = $this->stringOption('license-type');

        if ($licenseType !== null && ! in_array($licenseType, InitWizard::LICENSE_TYPES, true)) {
            throw new RuntimeException("--license-type invalide : \"{$licenseType}\" (attendu : ".implode(', ', InitWizard::LICENSE_TYPES).').');
        }

        foreach (['name', 'tagline'] as $option) {
            $value = $this->stringOption($option);

            if ($value !== null && mb_strlen($value) > InitWizard::MAX_TEXT_LENGTH) {
                throw new RuntimeException("--{$option} ne doit pas dépasser 255 caractères.");
            }
        }

        $softwareId = $this->positiveIdOption('software-id');
        $packageId = $this->positiveIdOption('package-id');

        return [
            'type' => $type,
            'mode' => match (true) {
                (bool) $this->option('link') => 'link',
                (bool) $this->option('create') => 'create',
                default => null,
            },
            // Without --type, an id only says which kind of thing to link.
            'id' => match ($type) {
                'module' => $packageId,
                'software' => $softwareId,
                default => null,
            },
            'parent_id' => $this->positiveIdOption('parent-software-id'),
            'name' => $this->stringOption('name') !== null ? trim((string) $this->stringOption('name')) : null,
            'tagline' => $this->stringOption('tagline'),
            'license_type' => $licenseType,
            'has_modules' => $this->option('has-modules') ? true : null,
        ];
    }

    /**
     * Non-interactive answers: the options, aps-cli's defaults, and a clear
     * error for anything that can only be picked interactively.
     *
     * @param  array{type: ?string, mode: ?string, id: ?int, parent_id: ?int, name: ?string, tagline: ?string, license_type: ?string, has_modules: ?bool}  $presets
     * @return array{type: string, mode: string, id: ?int, parent_id: ?int, name: ?string, tagline: ?string, license_type: ?string, has_modules: ?bool}
     */
    private function answersFromOptions(array $presets, string $detectedName): array
    {
        $type = $presets['type'] ?? 'software';
        $mode = $presets['mode'] ?? throw new RuntimeException('Précisez --link ou --create.');
        $id = $presets['id'] ?? ($type === 'module' ? $this->positiveIdOption('package-id') : $this->positiveIdOption('software-id'));

        if ($mode === 'link' && $id === null) {
            throw new RuntimeException('Précisez '.($type === 'module' ? '--package-id' : '--software-id').'.');
        }

        if ($mode === 'create' && $type === 'module' && $presets['parent_id'] === null) {
            throw new RuntimeException('Précisez --parent-software-id.');
        }

        return [
            ...$presets,
            'type' => $type,
            'mode' => $mode,
            'id' => $id,
            'name' => $presets['name'] ?? $detectedName,
            'license_type' => $presets['license_type'] ?? 'freemium',
            'has_modules' => $presets['has_modules'] ?? false,
        ];
    }

    /**
     * @param  array{type: string, mode: string, id: ?int, parent_id: ?int, name: ?string, tagline: ?string, license_type: ?string, has_modules: ?bool}  $answers
     * @return array<string, mixed>
     */
    private function linkOrCreate(PublisherClient $client, array $answers, string $fingerprint): array
    {
        $isModule = $answers['type'] === 'module';

        if ($answers['mode'] === 'link') {
            $id = (int) $answers['id'];

            return $isModule
                ? $this->linkWithRetry('module', fn (bool $force) => $client->linkPackage($id, $fingerprint, $force), (bool) $this->option('force-link'))
                : $this->linkWithRetry('software', fn (bool $force) => $client->linkSoftware($id, $fingerprint, $force), (bool) $this->option('force-link'));
        }

        $name = (string) $answers['name'];

        if ($isModule) {
            $package = $this->withSpinner('Création du module sur App Station...', fn () => $client->createPackage((int) $answers['parent_id'], $name));

            return $this->linkWithRetry('module', fn (bool $force) => $client->linkPackage((int) $package['id'], $fingerprint, $force), true);
        }

        $software = $this->withSpinner('Création du software sur App Station...', fn () => $client->createSoftware(
            $name, $answers['tagline'], $answers['license_type'], $answers['has_modules'],
        ));

        return $this->linkWithRetry('software', fn (bool $force) => $client->linkSoftware((int) $software['id'], $fingerprint, $force), true);
    }

    private function positiveIdOption(string $name): ?int
    {
        $value = $this->stringOption($name);

        if ($value === null) {
            return null;
        }

        if (! ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException("--{$name} doit être un identifiant positif, reçu : {$value}");
        }

        return (int) $value;
    }

    private function printNextSteps(string $environment, string $status): void
    {
        $this->newLine();
        $this->line('  Prochaine étape : php artisan aps-connect:doctor — vérifier que tout fonctionne.');

        if ($environment === 'production' && ! in_array($status, self::REGISTRA_SUBMITTED_STATUSES, true)) {
            $this->newLine();
            $this->components->warn(
                "Statut actuel : \"{$status}\" — la clé prod est générée localement mais ne sera active côté Registra qu'après soumission à la validation (statut \"pending_review\" ou \"published\"). aps-connect:doctor le signalera sans que ce soit une erreur.",
            );
        }
    }
}
