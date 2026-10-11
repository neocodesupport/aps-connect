<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use Neocode\ApsConnect\Console\Commands\Concerns\PromptsMissingOptions;
use Neocode\ApsConnect\Data\PackageRelease;
use Neocode\ApsConnect\Data\PublisherCredentials;
use Neocode\ApsConnect\Data\SoftwareRelease;
use Neocode\ApsConnect\Exceptions\MissingCredentialsException;
use Neocode\ApsConnect\Exceptions\PublisherRequestException;
use Neocode\ApsConnect\Exceptions\PublisherValidationException;
use Neocode\ApsConnect\Http\PublisherClient;
use Neocode\ApsConnect\Support\ApsCliSession;
use Neocode\ApsConnect\Support\ProjectConfigReader;
use Neocode\ApsConnect\Support\ReleaseArchiveBuilder;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\form;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Single entry point for both halves of shipping a release: `--pack` builds
 * the archive, `--publish` uploads a file, and either can be combined with
 * the other. Run with neither flag, it asks what to do first — every field
 * it still needs after that (version, channel, platform, the file to
 * publish, the token...) is only prompted for when it wasn't already
 * supplied via an option, mirroring `aps-cli`'s own interactive fallback
 * shape without re-asking for values the caller already gave.
 */
class ApsConnectReleaseCommand extends Command
{
    use PromptsMissingOptions;

    /**
     * Mirrors aps-cli/src/commands/release.ts's own CHANNELS/PLATFORMS.
     */
    private const array CHANNELS = ['stable', 'beta', 'rc', 'nightly'];

    private const array PLATFORMS = ['windows', 'macos', 'linux', 'android', 'ios', 'web', 'cli', 'browser_extension', 'universal'];

    /**
     * Identical to App Station's `App\Support\ReleaseUploadRules::SEMVER_PATTERN`
     * / aps-cli's own SEMVER_PATTERN (src/lib/validate.ts) — kept in sync with
     * both so an invalid version is rejected here rather than left to a 422.
     */
    private const string SEMVER_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    /**
     * The command signature.
     */
    protected $signature = 'aps-connect:release
        {file? : Path to the file to publish (only used with --publish and no --pack)}
        {--pack : Build a release archive}
        {--publish : Publish a release file}
        {--out= : Where to write the zip (default: storage/app/aps-connect/releases/SLUG-DATE.zip)}
        {--skip-npm : Skip the frontend build step even if package.json is present}
        {--build-command= : Override the frontend build command (default: "npm ci && npm run build")}
        {--no-obfuscate : Skip source obfuscation (comment stripping + safe local variable renaming)}
        {--release-version= : Semver version, e.g. 1.4.0 (required to publish)}
        {--channel= : stable|beta|rc|nightly}
        {--platform= : windows|macos|linux|android|ios|web|cli|browser_extension|universal}
        {--notes= : Release notes (markdown)}
        {--min-software-version= : Module only}
        {--max-software-version= : Module only}
        {--upload-name= : Filename as published on App Station (default: the file\'s own name)}
        {--token= : Publisher session token (default: the APS_TOKEN env var)}';

    /**
     * The command description.
     */
    protected $description = 'Build and/or publish a release for the linked software/module — asks what to do when run without --pack/--publish.';

    /**
     * Execute the console command.
     */
    public function handle(ProjectConfigReader $configReader, ReleaseArchiveBuilder $builder, HttpFactory $http, ApsCliSession $session): int
    {
        [$pack, $publish] = $this->resolveModes();

        $file = $this->stringArgument('file');

        if ($pack) {
            $packed = $this->pack($configReader, $builder, standalone: ! $publish);

            if ($packed === null) {
                return self::FAILURE;
            }

            $file = $packed;
        }

        if (! $publish) {
            return self::SUCCESS;
        }

        return $this->publish($configReader, $http, $session, $file);
    }

    /**
     * @return array{bool, bool}
     */
    private function resolveModes(): array
    {
        $pack = (bool) $this->option('pack');
        $publish = (bool) $this->option('publish');

        if ($pack || $publish) {
            return [$pack, $publish];
        }

        if (! $this->input->isInteractive()) {
            return [true, true];
        }

        $choice = $this->choiceString(
            'Que voulez-vous faire ?',
            ['Empaqueter et publier', 'Empaqueter seulement', 'Publier un fichier existant'],
            'Empaqueter et publier',
        );

        return match ($choice) {
            'Empaqueter seulement' => [true, false],
            'Publier un fichier existant' => [false, true],
            default => [true, true],
        };
    }

    private function pack(ProjectConfigReader $configReader, ReleaseArchiveBuilder $builder, bool $standalone): ?string
    {
        try {
            $identity = $configReader->projectIdentity();
        } catch (MissingCredentialsException $e) {
            $this->components->error("Configuration incomplète : {$e->getMessage()}");

            return null;
        }

        $runtimeLocalConfig = $this->runtimeLocalConfig($configReader);

        if ($runtimeLocalConfig === null) {
            return null;
        }

        try {
            $zipPath = $this->withSpinner("Empaquetage de \"{$identity->name}\" (dépendances, build, obfuscation)...", fn () => $builder->build(
                slug: $identity->name,
                outputPath: $this->stringOption('out'),
                skipNpm: (bool) $this->option('skip-npm'),
                buildCommand: $this->stringOption('build-command'),
                skipObfuscation: (bool) $this->option('no-obfuscate'),
                runtimeLocalConfig: $runtimeLocalConfig,
            ));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return null;
        }

        $this->components->info("Archive écrite : {$zipPath}");

        if ($standalone) {
            $this->components->info("Suite : php artisan aps-connect:release --publish \"{$zipPath}\" --release-version=<version>");
        }

        return $zipPath;
    }

    /**
     * The api key the archive ships so the deployed copy can verify
     * licences (ReleaseArchiveBuilder::runtimeLocalConfig()) — refused when
     * appstation.conf.local.json has no key or belongs to another
     * environment than appstation.conf.json, and flagged when it is still
     * the development key customers can't use.
     *
     * @return array<string, mixed>|null
     */
    private function runtimeLocalConfig(ProjectConfigReader $configReader): ?array
    {
        $projectEnvironment = $configReader->projectConfig()['environment'] ?? 'production';
        $runtime = ReleaseArchiveBuilder::runtimeLocalConfig($configReader->localConfig());
        $apiKey = $runtime['auth']['apiKey'];

        if (! is_string($apiKey) || $apiKey === '') {
            $this->components->error('appstation.conf.local.json n\'a pas de clé API (auth.apiKey) : l\'application déployée ne pourrait vérifier aucune licence. Exécutez "php artisan aps-connect:fetch-key".');

            return null;
        }

        if ($runtime['environment'] !== $projectEnvironment) {
            $this->components->error(sprintf(
                'appstation.conf.local.json contient des identifiants "%s", pas "%s" comme appstation.conf.json. Exécutez "php artisan aps-connect:fetch-key" pour les réaligner avant d\'empaqueter.',
                is_string($runtime['environment']) ? $runtime['environment'] : '?',
                is_string($projectEnvironment) ? $projectEnvironment : '?',
            ));

            return null;
        }

        if ($projectEnvironment !== 'production') {
            $this->components->warn(
                "L'archive embarque la clé Registra \"{$projectEnvironment}\" : les licences de vos clients ne seront pas vérifiées contre Registra production. Pour une release client, exécutez d'abord \"php artisan aps-connect:fetch-key --production\".",
            );
        }

        return $runtime;
    }

    private function publish(ProjectConfigReader $configReader, HttpFactory $http, ApsCliSession $session, ?string $file): int
    {
        $channel = $this->stringOption('channel');
        $platform = $this->stringOption('platform');
        $version = $this->stringOption('release-version');

        if ($this->input->isInteractive()) {
            $answers = $this->askPublishForm($configReader, $file, $channel, $platform, $version);

            if ($answers === null) {
                $this->components->info('Publication annulée.');

                return self::SUCCESS;
            }

            [$file, $channel, $platform, $version] = $answers;
        }

        $channel ??= 'stable';
        $platform ??= 'universal';

        if ($file === null || ! is_file($file)) {
            $this->components->error('Fichier introuvable'.($file !== null ? " : {$file}" : ' : aucun fichier fourni.'));

            return self::FAILURE;
        }

        if (! in_array($channel, self::CHANNELS, true)) {
            $this->components->error('--channel invalide : "'.$channel.'" (attendu : '.implode(', ', self::CHANNELS).').');

            return self::FAILURE;
        }

        if (! in_array($platform, self::PLATFORMS, true)) {
            $this->components->error('--platform invalide : "'.$platform.'" (attendu : '.implode(', ', self::PLATFORMS).').');

            return self::FAILURE;
        }

        if ($version === null) {
            $this->components->error('--release-version est requis (ex. 1.4.0).');

            return self::FAILURE;
        }

        if (! $this->isValidVersion($version)) {
            $this->components->error("--release-version doit être un semver valide (ex. 1.4.0, max 20 caractères), reçu : {$version}");

            return self::FAILURE;
        }

        try {
            $identity = $configReader->projectIdentity();
        } catch (MissingCredentialsException $e) {
            $this->components->error("Configuration incomplète : {$e->getMessage()}");

            return self::FAILURE;
        }

        $minSoftwareVersion = $this->stringOption('min-software-version');
        $maxSoftwareVersion = $this->stringOption('max-software-version');

        if (! $identity->isModule() && ($minSoftwareVersion !== null || $maxSoftwareVersion !== null)) {
            $this->components->error('--min-software-version/--max-software-version ne sont disponibles que pour un module.');

            return self::FAILURE;
        }

        try {
            $credentials = $this->resolvePublisherCredentials($configReader, $session);
        } catch (MissingCredentialsException $e) {
            $this->components->error("Configuration incomplète : {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($credentials === null) {
            $this->components->error('Aucun token éditeur : passez --token, définissez APS_TOKEN, ou exécutez "aps login" (aps-cli).');

            return self::FAILURE;
        }

        $uploadName = $this->stringOption('upload-name') ?? basename($file);
        $notes = $this->stringOption('notes');

        $fields = array_filter([
            'version' => $version,
            'channel' => $channel,
            'platform' => $platform,
            'release_notes' => $notes,
            'min_software_version' => $minSoftwareVersion,
            'max_software_version' => $maxSoftwareVersion,
        ], static fn (?string $value): bool => $value !== null);

        $client = new PublisherClient($credentials, $http);

        try {
            $data = $this->withSpinner(
                "Publication de la release {$version} ({$channel}, {$platform})...",
                fn () => $identity->isModule()
                    ? $client->createPackageRelease($identity->id, $fields, $file, $uploadName)
                    : $client->createSoftwareRelease($identity->id, $fields, $file, $uploadName),
            );
        } catch (PublisherValidationException $e) {
            $this->components->error('Validation refusée par App Station :');

            foreach ($e->errors as $field => $messages) {
                foreach ($messages as $message) {
                    $this->line("  - {$field}: {$message}");
                }
            }

            return self::FAILURE;
        } catch (PublisherRequestException $e) {
            $this->components->error("Échec de la publication : {$e->getMessage()}");

            return self::FAILURE;
        }

        $release = $identity->isModule() ? PackageRelease::fromArray($data) : SoftwareRelease::fromArray($data);

        $this->components->info(
            "Release {$release->version}".($release->platform !== null ? " ({$release->platform})" : '')." publiée ({$release->channel}).",
        );

        if ($release->signature === null) {
            $this->components->warn(
                'Signature indisponible pour le moment (aucun signingSecret généré pour ce software) — sera calculée rétroactivement.',
            );
        }

        $this->line("  Checksum  : {$release->checksum}");
        $this->line('  Signature : '.($release->signature ?? '(en attente)'));

        return self::SUCCESS;
    }

    /**
     * Same precedence as aps-cli's resolveSession(): an explicit --token, then
     * APS_TOKEN (paired with APPSTATION_BASE_URL when set), then the session
     * `aps login` stored on disk — which carries its own base url, so a token
     * from `aps login --dev` is never sent to the production instance — and
     * only then an interactive prompt. Tokens that don't come with a base url
     * of their own target the project's `appstation.baseUrl`.
     *
     * @throws MissingCredentialsException
     */
    private function resolvePublisherCredentials(ProjectConfigReader $configReader, ApsCliSession $session): ?PublisherCredentials
    {
        $token = $this->stringOption('token');

        if ($token !== null) {
            return new PublisherCredentials($token, $configReader->appStationBaseUrl());
        }

        $envToken = trim((string) getenv('APS_TOKEN'));

        if ($envToken !== '') {
            $envBaseUrl = trim((string) getenv('APPSTATION_BASE_URL'));

            return new PublisherCredentials($envToken, $envBaseUrl !== '' ? rtrim($envBaseUrl, '/') : $configReader->appStationBaseUrl());
        }

        $stored = $session->credentials();

        if ($stored !== null) {
            $this->components->info("Session aps login utilisée ({$stored->baseUrl}).");

            return $stored;
        }

        $token = $this->secretIfInteractive('Token éditeur (APS_TOKEN)');

        return $token !== null ? new PublisherCredentials($token, $configReader->appStationBaseUrl()) : null;
    }

    /**
     * The publish questions not already answered by options, as one Laravel
     * Prompts form ending on a recap: Ctrl+U goes back to any of them, with
     * its previous answer kept, until the upload is confirmed.
     *
     * @return array{string, string, string, string}|null null when the recap is declined
     */
    private function askPublishForm(ProjectConfigReader $configReader, ?string $file, ?string $channel, ?string $platform, ?string $version): ?array
    {
        $hint = 'Ctrl+U pour revenir à la question précédente.';

        $responses = form()
            ->addIf($file === null, fn (array $r, mixed $previous) => text(
                label: 'Fichier à publier (chemin)',
                default: is_string($previous) ? $previous : '',
                required: 'Le fichier est obligatoire.',
                validate: static fn (string $value): ?string => is_file($value) ? null : "Fichier introuvable : {$value}",
            ), name: 'file')
            ->addIf($channel === null, fn (array $r, mixed $previous) => select(
                label: 'Channel',
                options: self::CHANNELS,
                default: is_string($previous) ? $previous : 'stable',
                hint: $hint,
            ), name: 'channel')
            ->addIf($platform === null, fn (array $r, mixed $previous) => select(
                label: 'Plateforme',
                options: self::PLATFORMS,
                default: is_string($previous) ? $previous : 'universal',
                scroll: 9,
                hint: $hint,
            ), name: 'platform')
            ->addIf($version === null, fn (array $r, mixed $previous) => text(
                label: 'Version (semver, ex. 1.4.0)',
                default: is_string($previous) ? $previous : '',
                required: 'La version est obligatoire.',
                validate: fn (string $value): ?string => $this->isValidVersion($value) ? null : "Version invalide (semver, max 20 caractères) : {$value}",
                hint: $hint,
            ), name: 'version')
            ->add(function (array $r) use ($configReader, $file, $channel, $platform, $version, $hint) {
                $name = rescue(fn () => $configReader->projectIdentity()->name, 'ce projet', report: false);

                return confirm(
                    label: sprintf(
                        'Publier « %s » pour %s en version %s (%s, %s) ?',
                        basename((string) ($file ?? $r['file'])),
                        $name,
                        $version ?? $r['version'],
                        $channel ?? $r['channel'],
                        $platform ?? $r['platform'],
                    ),
                    default: true,
                    yes: 'Publier',
                    no: 'Annuler',
                    hint: $hint,
                );
            }, name: 'confirmed')
            ->submit();

        if ($responses['confirmed'] !== true) {
            return null;
        }

        return [
            (string) ($file ?? $responses['file']),
            (string) ($channel ?? $responses['channel']),
            (string) ($platform ?? $responses['platform']),
            (string) ($version ?? $responses['version']),
        ];
    }

    private function isValidVersion(string $value): bool
    {
        return strlen($value) <= 20 && preg_match(self::SEMVER_PATTERN, $value) === 1;
    }
}
