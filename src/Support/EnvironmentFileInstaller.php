<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Support;

/**
 * The `.env` mechanics behind ApsConnectInstallCommand's first run. Kept
 * deliberately dumb — line-based find/replace/append, no attempt at a full
 * dotenv parser — since the only values it ever writes are the handful the
 * command collects itself (APP_URL, DB_*).
 */
final class EnvironmentFileInstaller
{
    private const array MINIMAL_TEMPLATE_LINES = [
        'APP_NAME=Laravel',
        'APP_ENV=production',
        'APP_KEY=',
        'APP_DEBUG=false',
        'APP_URL=http://localhost',
        '',
        'LOG_CHANNEL=stack',
        '',
        'DB_CONNECTION=sqlite',
    ];

    public function __construct(private readonly string $basePath) {}

    public function envExists(): bool
    {
        return is_file($this->envPath());
    }

    /**
     * Copies `.env.example` verbatim when the project ships one (preserving
     * whatever extra keys it declares — MAIL_*, third-party service keys,
     * ...), otherwise falls back to a minimal template with just enough to
     * boot. Either way, the values this class's caller actually collected
     * (APP_URL, DB_*) are applied afterward via setValues().
     */
    public function createFromExample(): void
    {
        $examplePath = $this->basePath.'/.env.example';

        if (is_file($examplePath)) {
            copy($examplePath, $this->envPath());

            return;
        }

        file_put_contents($this->envPath(), implode("\n", self::MINIMAL_TEMPLATE_LINES)."\n");
    }

    /**
     * @param  array<string, string>  $values
     */
    public function setValues(array $values): void
    {
        $lines = $this->readLines();

        foreach ($values as $key => $value) {
            $lines = $this->setValue($lines, $key, $value);
        }

        file_put_contents($this->envPath(), implode("\n", $lines)."\n");
    }

    /**
     * Laravel 11+ no longer creates the sqlite file for you — `migrate`
     * fails outright without it when DB_CONNECTION=sqlite.
     */
    public function ensureSqliteFileExists(): void
    {
        $path = $this->basePath.'/database/database.sqlite';

        if (is_file($path)) {
            return;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }

        touch($path);
    }

    /**
     * @return list<string>
     */
    private function readLines(): array
    {
        if (! is_file($this->envPath())) {
            return [];
        }

        $contents = file_get_contents($this->envPath());

        if ($contents === false) {
            return [];
        }

        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        return explode("\n", rtrim($contents, "\n"));
    }

    /**
     * Replaces an existing (optionally already-commented-out) `KEY=...`
     * line in place, or appends a new one when the key isn't declared at
     * all yet.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function setValue(array $lines, string $key, string $value): array
    {
        $formatted = $key.'='.$this->quoteIfNeeded($value);
        $pattern = '/^#?\s*'.preg_quote($key, '/').'\s*=/';

        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                $lines[$index] = $formatted;

                return $lines;
            }
        }

        $lines[] = $formatted;

        return $lines;
    }

    private function quoteIfNeeded(string $value): string
    {
        if ($value === '' || preg_match('/\s/', $value) === 1) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }

    private function envPath(): string
    {
        return $this->basePath.'/.env';
    }
}
