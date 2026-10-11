<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Support;

/**
 * Writes the two project files ProjectConfigReader reads, in the exact
 * layout aps-cli's src/lib/config.ts writes them (2-space JSON, trailing
 * newline), plus the .gitignore block that keeps the secret one out of git.
 */
final readonly class ProjectConfigWriter
{
    public const string API_KEY_ENV_VAR = 'REGISTRA_API_KEY';

    private const string GITIGNORE_HEADER = '# App Station / Registra (genere par aps init)';

    private const array GITIGNORE_PATTERNS = ['appstation.conf.local.json', '.env.registra', 'appstation.manifest.signed.json'];

    public function __construct(private string $basePath) {}

    public function projectConfigExists(): bool
    {
        return is_file($this->basePath.'/appstation.conf.json');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function writeProjectConfig(array $config): void
    {
        file_put_contents($this->basePath.'/appstation.conf.json', $this->encode($config));
    }

    /**
     * Holds the Registra api key and signing secret of ONE environment only.
     */
    public function writeLocalConfig(string $environment, string $apiKey, string $signingSecret): void
    {
        $path = $this->basePath.'/appstation.conf.local.json';

        file_put_contents($path, $this->encode([
            'environment' => $environment,
            'auth' => ['apiKey' => $apiKey],
            'signingSecret' => $signingSecret,
        ]));

        // Best-effort, like aps-cli: no-op ACL semantics on Windows.
        @chmod($path, 0600);
    }

    /**
     * Appends whichever secret-file patterns .gitignore is missing, under
     * aps-cli's header. Returns whether the file changed.
     */
    public function ensureGitignorePatched(): bool
    {
        $path = $this->basePath.'/.gitignore';
        $current = is_file($path) ? (string) file_get_contents($path) : '';

        $existing = array_map(trim(...), preg_split('/\r?\n/', $current) ?: []);
        $missing = array_values(array_diff(self::GITIGNORE_PATTERNS, $existing));

        if ($missing === []) {
            return false;
        }

        $separator = match (true) {
            $current === '' => '',
            ! str_ends_with($current, "\n") => "\n\n",
            default => "\n",
        };

        file_put_contents($path, $current.$separator.implode("\n", [self::GITIGNORE_HEADER, ...$missing])."\n");

        return true;
    }

    /**
     * JSON.stringify(value, null, 2) + "\n".
     *
     * @param  array<string, mixed>  $data
     */
    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json)."\n";
    }
}
