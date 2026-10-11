<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use Neocode\ApsConnect\Console\Commands\Concerns\PromptsMissingOptions;
use Neocode\ApsConnect\Data\PublisherCredentials;
use Neocode\ApsConnect\Exceptions\ProjectAlreadyLinkedException;
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
 */
class ApsConnectInitCommand extends Command
{
    use PromptsMissingOptions;

    /**
     * Below these statuses, a production key issued by `registra/init` is
     * not active on Registra yet — see printNextSteps().
     */
    private const array REGISTRA_SUBMITTED_STATUSES = ['pending_review', 'published'];

    /**
     * App Station's App\Enums\LicenseType values.
     */
    private const array LICENSE_TYPES = ['free', 'freemium', 'paid', 'open_source', 'subscription'];

    /**
     * App Station's `max:255` on a software's/module's name and tagline.
     */
    private const int MAX_TEXT_LENGTH = 255;

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
        $type = $this->stringOption('type') ?? $this->choiceString('Ce projet est-il un software ou un module ?', ['software', 'module'], 'software');
        $environment = $this->stringOption('env') ?? 'development';

        if (! in_array($type, ['software', 'module'], true)) {
            $this->components->error("--type invalide : \"{$type}\" (attendu : software, module).");

            return self::FAILURE;
        }

        if (! in_array($environment, ['development', 'production'], true)) {
            $this->components->error("--env invalide : \"{$environment}\" (attendu : development, production).");

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

            if (! $this->confirm('appstation.conf.json existe déjà. Écraser ?', false)) {
                $this->components->info('Annulé. Utilisez "php artisan aps-connect:init --yes" pour écraser sans confirmation.');

                return self::SUCCESS;
            }
        }

        $mode = $this->resolveMode();

        if ($mode === null) {
            $this->components->error('Précisez --link ou --create.');

            return self::FAILURE;
        }

        $client = new PublisherClient($credentials, $http);
        $fingerprint = $inspector->fingerprint();

        $this->components->info("aps-connect:init — {$type} / {$mode} / {$environment}");

        try {
            $target = $type === 'module'
                ? $this->initModule($client, $mode, $fingerprint, $inspector)
                : $this->initSoftware($client, $mode, $fingerprint, $inspector);

            $response = $client->registraInit($type, (int) $target['id'], $environment, $fingerprint);
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

    private function resolveMode(): ?string
    {
        if ($this->option('link')) {
            return 'link';
        }

        if ($this->option('create')) {
            return 'create';
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $link = 'Lier ce projet à un software/module App Station existant';
        $create = 'Créer un nouveau software/module sur App Station';

        return $this->choiceString('Que souhaitez-vous faire ?', [$link, $create], $link) === $create ? 'create' : 'link';
    }

    /**
     * @return array<string, mixed>
     */
    private function initSoftware(PublisherClient $client, string $mode, string $fingerprint, ProjectInspector $inspector): array
    {
        if ($mode === 'create') {
            $software = $client->createSoftware(
                $this->resolveName($inspector),
                $this->resolveTagline(),
                $this->resolveLicenseType(),
            );

            return $this->linkWithRetry('software', fn (bool $force) => $client->linkSoftware((int) $software['id'], $fingerprint, $force), true);
        }

        $id = $this->positiveIdOption('software-id')
            ?? $this->pickId('Software App Station à lier ?', $client->listSoftwares(), 'Aucun software trouvé sur votre compte. Utilisez --create.', '--software-id');

        return $this->linkWithRetry('software', fn (bool $force) => $client->linkSoftware($id, $fingerprint, $force), (bool) $this->option('force-link'));
    }

    /**
     * @return array<string, mixed>
     */
    private function initModule(PublisherClient $client, string $mode, string $fingerprint, ProjectInspector $inspector): array
    {
        if ($mode === 'create') {
            // Only softwares that offer modules can be a module's parent.
            // `has_modules` can only be turned on in App Station's web UI
            // (its API neither sets nor updates it), hence the hint below.
            $parentId = $this->positiveIdOption('parent-software-id')
                ?? $this->pickId(
                    'Software parent du module ?',
                    array_values(array_filter($client->listSoftwares(), static fn (array $software): bool => ($software['has_modules'] ?? false) === true)),
                    'Aucun de vos softwares ne propose de modules. Activez « Propose des modules » sur le software parent dans App Station, puis relancez.',
                    '--parent-software-id',
                );

            $package = $client->createPackage($parentId, $this->resolveName($inspector));

            return $this->linkWithRetry('module', fn (bool $force) => $client->linkPackage((int) $package['id'], $fingerprint, $force), true);
        }

        $id = $this->positiveIdOption('package-id')
            ?? $this->pickId('Module App Station à lier ?', $client->listPackages(), 'Aucun module trouvé sur votre compte. Utilisez --create --type=module.', '--package-id');

        return $this->linkWithRetry('module', fn (bool $force) => $client->linkPackage($id, $fingerprint, $force), (bool) $this->option('force-link'));
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
            $result = $link($force);
        } catch (ProjectAlreadyLinkedException $e) {
            if ($force) {
                throw $e;
            }

            if (! $this->input->isInteractive() || ! $this->confirm("Ce {$label} est déjà lié à un autre dépôt local. Forcer la liaison ?", false)) {
                throw new RuntimeException("Ce {$label} est déjà lié à un autre dépôt local. Relancez avec --force-link pour forcer la liaison.");
            }

            $result = $link(true);
        }

        if ($result['registra_dev_sync_error'] !== null) {
            $this->components->warn("Sync Registra dev : {$result['registra_dev_sync_error']}");
        }

        return $result['data'];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function pickId(string $question, array $items, string $emptyMessage, string $option): int
    {
        if ($items === []) {
            throw new RuntimeException($emptyMessage);
        }

        if (! $this->input->isInteractive()) {
            throw new RuntimeException("Précisez {$option}.");
        }

        $choices = [];

        foreach ($items as $item) {
            $parent = is_array($item['software'] ?? null) && is_string($item['software']['name'] ?? null) ? "parent : {$item['software']['name']}, " : '';
            $choices['#'.$item['id'].' '.$item['name']." ({$parent}{$item['status']})"] = (int) $item['id'];
        }

        $labels = array_keys($choices);

        return $choices[$this->choiceString($question, $labels, $labels[0])];
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

    /**
     * --name, else the detected name — prompted for (with it as the default)
     * when interactive, used as-is otherwise.
     */
    private function resolveName(ProjectInspector $inspector): string
    {
        $name = trim($this->stringOption('name') ?? $this->askWithDefault('Nom du software/module', $inspector->name()));

        if ($name === '' || mb_strlen($name) > self::MAX_TEXT_LENGTH) {
            throw new RuntimeException('Le nom est obligatoire et ne doit pas dépasser 255 caractères.');
        }

        return $name;
    }

    /**
     * Optional on App Station; asked (empty skips it) when interactive.
     */
    private function resolveTagline(): ?string
    {
        $tagline = $this->stringOption('tagline');

        if ($tagline === null && $this->input->isInteractive()) {
            $answer = $this->ask('Tagline (optionnel, 255 caractères max)');
            $tagline = is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
        }

        if ($tagline !== null && mb_strlen($tagline) > self::MAX_TEXT_LENGTH) {
            throw new RuntimeException('La tagline ne doit pas dépasser 255 caractères.');
        }

        return $tagline;
    }

    /**
     * App Station's LicenseType; it defaults to freemium when omitted.
     */
    private function resolveLicenseType(): string
    {
        $licenseType = $this->stringOption('license-type')
            ?? $this->choiceString('Type de licence', self::LICENSE_TYPES, 'freemium');

        if (! in_array($licenseType, self::LICENSE_TYPES, true)) {
            throw new RuntimeException("--license-type invalide : \"{$licenseType}\" (attendu : ".implode(', ', self::LICENSE_TYPES).').');
        }

        return $licenseType;
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
