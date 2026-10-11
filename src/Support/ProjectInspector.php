<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Support;

use Illuminate\Support\Facades\Process;

/**
 * What `aps-connect:init` needs to know about the repository it runs in,
 * computed exactly like aps-cli (src/lib/fingerprint.ts, src/lib/stack.ts)
 * so both tools identify the same repository the same way — App Station
 * compares fingerprints to tell "this repo" from "another repo" on link.
 */
final readonly class ProjectInspector
{
    /**
     * Same order as aps-cli's PROBES; package.json is checked first.
     */
    private const array STACK_PROBES = [
        'pubspec.yaml' => 'flutter',
        'composer.json' => 'php',
        'requirements.txt' => 'python',
        'pyproject.toml' => 'python',
        'go.mod' => 'go',
        'Cargo.toml' => 'rust',
    ];

    public function __construct(private string $basePath) {}

    /**
     * sha256 of the `origin` remote url (stable across clones and
     * teammates), falling back to `local:<absolute path>` (stable on this
     * machine only) when there is no git remote.
     */
    public function fingerprint(): string
    {
        $result = Process::path($this->basePath)->run(['git', 'remote', 'get-url', 'origin']);
        $remote = $result->successful() ? trim($result->output()) : '';

        return hash('sha256', $remote !== '' ? $remote : 'local:'.(realpath($this->basePath) ?: $this->basePath));
    }

    /**
     * Whether git tracks $file anyway — typically a secret committed before
     * .gitignore listed it (ignoring never untracks retroactively).
     * Best-effort: false outside a git repository or without git.
     */
    public function isTrackedByGit(string $file): bool
    {
        return Process::path($this->basePath)->run(['git', 'ls-files', '--error-unmatch', $file])->successful();
    }

    public function stack(): ?string
    {
        $package = $this->packageJson();

        if ($package !== null) {
            $dependencies = array_merge(
                is_array($package['dependencies'] ?? null) ? $package['dependencies'] : [],
                is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : [],
            );

            foreach (['electron' => 'electron', 'next' => 'nextjs', 'react' => 'react', 'vue' => 'vue'] as $dependency => $stack) {
                if (isset($dependencies[$dependency])) {
                    return $stack;
                }
            }

            return 'node';
        }

        foreach (self::STACK_PROBES as $file => $stack) {
            if (file_exists($this->basePath.'/'.$file)) {
                return $stack;
            }
        }

        return file_exists($this->basePath.'/Directory.Build.props') ? 'dotnet' : null;
    }

    /**
     * package.json's `name`, else the directory name.
     */
    public function name(): string
    {
        $name = $this->packageJson()['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : basename(realpath($this->basePath) ?: $this->basePath);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function packageJson(): ?array
    {
        $path = $this->basePath.'/package.json';

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
