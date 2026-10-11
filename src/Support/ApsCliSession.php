<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Support;

use Neocode\ApsConnect\Data\PublisherCredentials;
use RuntimeException;

/**
 * Reads and writes the publisher session shared with `aps login` (aps-cli),
 * stored by it or by `aps-connect:login`, so
 * `aps-connect:release --publish` works right after logging in with the CLI
 * without re-supplying the token. Mirrors aps-cli's src/lib/credentials.ts:
 * `%APPDATA%\app-station\credentials.json` on Windows,
 * `~/.config/app-station/credentials.json` elsewhere, shaped
 * `{"appstation": {"baseUrl": ..., "accessToken": ...}}`.
 */
final readonly class ApsCliSession
{
    public function __construct(
        private ?string $credentialsPath = null,
    ) {}

    /**
     * The stored session, or null when there is none (not logged in, or a
     * file that doesn't carry both a token and a base url).
     */
    public function credentials(): ?PublisherCredentials
    {
        $path = $this->credentialsPath ?? self::defaultCredentialsPath();

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $appStation = is_array($decoded) ? ($decoded['appstation'] ?? null) : null;

        if (! is_array($appStation)) {
            return null;
        }

        $token = $appStation['accessToken'] ?? null;
        $baseUrl = $appStation['baseUrl'] ?? null;

        if (! is_string($token) || trim($token) === '' || ! is_string($baseUrl) || trim($baseUrl) === '') {
            return null;
        }

        return new PublisherCredentials(trim($token), rtrim(trim($baseUrl), '/'));
    }

    /**
     * Stores the session where aps-cli's writeCredentials() does, in its
     * layout, so `aps` and aps-connect share one login.
     *
     * @throws RuntimeException when there is no home directory to write to
     */
    public function store(PublisherCredentials $credentials): void
    {
        $path = $this->resolvePath();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0700, recursive: true);
        }

        $json = json_encode(
            ['appstation' => ['baseUrl' => $credentials->baseUrl, 'accessToken' => $credentials->token]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        file_put_contents($path, str_replace('    ', '  ', $json)."\n");

        // The token is stored in clear: owner-only, best-effort (no-op ACL
        // semantics on Windows), like aps-cli.
        @chmod($path, 0600);
    }

    /**
     * Deletes the stored session. Returns whether there was one.
     */
    public function clear(): bool
    {
        $path = $this->credentialsPath ?? self::defaultCredentialsPath();

        return $path !== null && is_file($path) && unlink($path);
    }

    public function path(): ?string
    {
        return $this->credentialsPath ?? self::defaultCredentialsPath();
    }

    private function resolvePath(): string
    {
        return $this->path() ?? throw new RuntimeException(
            'Impossible de localiser le dossier de configuration (ni APPDATA/USERPROFILE, ni HOME).',
        );
    }

    public static function defaultCredentialsPath(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $appData = getenv('APPDATA') ?: null;
            $home = getenv('USERPROFILE') ?: null;
            $dir = $appData ?? ($home !== null ? $home.'\\AppData\\Roaming' : null);

            return $dir !== null ? $dir.'\\app-station\\credentials.json' : null;
        }

        $home = getenv('HOME') ?: null;

        return $home !== null ? $home.'/.config/app-station/credentials.json' : null;
    }
}
