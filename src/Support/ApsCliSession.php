<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Support;

use Neocode\ApsConnect\Data\PublisherCredentials;

/**
 * Reads the publisher session `aps login` (aps-cli) leaves on disk, so
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
