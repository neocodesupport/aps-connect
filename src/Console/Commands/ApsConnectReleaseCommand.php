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
use Neocode\ApsConnect\Support\ProjectConfigReader;
use Neocode\ApsConnect\Support\ReleaseArchiveBuilder;
use RuntimeException;

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
    public function handle(ProjectConfigReader $configReader, ReleaseArchiveBuilder $builder, HttpFactory $http): int
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

        return $this->publish($configReader, $http, $file);
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

        $choice = $this->choice(
            'Que voulez-vous faire ?',
            ['Empaqueter et publier', 'Empaqueter seulement', 'Publier un fichier existant'],
            0,
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

        $this->components->info("Empaquetage de \"{$identity->name}\"...");

        try {
            $zipPath = $builder->build(
                slug: $identity->name,
                outputPath: $this->stringOption('out'),
                skipNpm: (bool) $this->option('skip-npm'),
                buildCommand: $this->stringOption('build-command'),
                skipObfuscation: (bool) $this->option('no-obfuscate'),
            );
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

    private function publish(ProjectConfigReader $configReader, HttpFactory $http, ?string $file): int
    {
        $file ??= $this->askUntilValid(
            'Fichier à publier (chemin)',
            fn (string $value): bool|string => is_file($value) ? true : "Fichier introuvable : {$value}",
        );

        if ($file === null || ! is_file($file)) {
            $this->components->error('Fichier introuvable'.($file !== null ? " : {$file}" : ' : aucun fichier fourni.'));

            return self::FAILURE;
        }

        $channel = $this->stringOption('channel') ?? $this->choiceString('Channel', self::CHANNELS, 'stable');

        if (! in_array($channel, self::CHANNELS, true)) {
            $this->components->error('--channel invalide : "'.$channel.'" (attendu : '.implode(', ', self::CHANNELS).').');

            return self::FAILURE;
        }

        $platform = $this->stringOption('platform') ?? $this->choiceString('Plateforme', self::PLATFORMS, 'universal');

        if (! in_array($platform, self::PLATFORMS, true)) {
            $this->components->error('--platform invalide : "'.$platform.'" (attendu : '.implode(', ', self::PLATFORMS).').');

            return self::FAILURE;
        }

        $version = $this->stringOption('release-version') ?? $this->askUntilValid(
            'Version (semver, ex. 1.4.0)',
            fn (string $value): bool|string => $this->isValidVersion($value) ? true : "Version invalide (semver, max 20 caractères) : {$value}",
        );

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
            $baseUrl = $configReader->appStationBaseUrl();
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

        $token = $this->stringOption('token')
            ?? (getenv('APS_TOKEN') ?: null)
            ?? $this->secretIfInteractive('Token éditeur (APS_TOKEN)');

        if ($token === null) {
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

        $client = new PublisherClient(new PublisherCredentials($token, $baseUrl), $http);

        $this->components->info("Publication de la release {$version} ({$channel}, {$platform})...");

        try {
            $data = $identity->isModule()
                ? $client->createPackageRelease($identity->id, $fields, $file, $uploadName)
                : $client->createSoftwareRelease($identity->id, $fields, $file, $uploadName);
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

    private function isValidVersion(string $value): bool
    {
        return strlen($value) <= 20 && preg_match(self::SEMVER_PATTERN, $value) === 1;
    }
}
