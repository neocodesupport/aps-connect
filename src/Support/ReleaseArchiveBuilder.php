<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Support;

use FilesystemIterator;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use ZipArchive;

/**
 * Builds a ready-to-deploy Laravel web source zip: stages an excluded copy
 * of the project in a temp directory (never in-place — running `composer
 * install --no-dev` directly in the developer's working copy would strip
 * their own dev dependencies), installs production dependencies and an
 * optional frontend build there, then zips the result.
 *
 * Deliberately does not attempt to orchestrate a NativePHP native build —
 * see ApsConnectReleasePublishCommand, which accepts any pre-built file
 * (a NativePHP installer included) directly.
 */
final class ReleaseArchiveBuilder
{
    /**
     * Excluded unconditionally, regardless of .apsignore content — these
     * hold secrets (dev Registra API key, HMAC signing secret from
     * `aps sign`) and must never end up in a published artifact. Since
     * .apsignore has no negation syntax, nothing a project declares there
     * can ever cancel these.
     */
    private const array HARD_EXCLUDES = [
        '.env',
        '.env.*',
        'appstation.conf.local.json',
    ];

    private const array DEFAULT_EXCLUDES = [
        '.git',
        '.github',
        '.idea',
        '.vscode',
        'node_modules',
        'tests',
        'storage/logs',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        '.phpunit.cache',
        '*.log',
        '*.tmp',
        'database/*.sqlite',
        '.DS_Store',
        // Internal/dev-context docs — README*/CONTRIBUTING/CHANGELOG explain
        // the project to its own contributors, not to a customer running
        // it; AGENTS.md/CLAUDE.md/.ai/.cursor/.junie carry AI-assistant
        // context (often architecture/business-logic notes) that has no
        // reason to leave the publisher's machine; boost.json/.mcp.json are
        // dev tooling config, not application behaviour.
        'README*',
        'CONTRIBUTING.md',
        'CHANGELOG.md',
        'docs',
        'AGENTS.md',
        'CLAUDE.md',
        '.ai',
        '.cursor',
        '.junie',
        'boost.json',
        '.mcp.json',
    ];

    public function __construct(private readonly string $basePath) {}

    public function build(string $slug, ?string $outputPath = null, bool $skipNpm = false, ?string $buildCommand = null, bool $skipObfuscation = false): string
    {
        $patterns = [...self::HARD_EXCLUDES, ...self::DEFAULT_EXCLUDES, ...$this->readApsIgnore()];

        // Staged outside the project entirely (not under storage/app), so
        // the staging copy is never itself picked up while scanning
        // $basePath — running `pack` twice in a row would otherwise risk
        // copying a previous run's staging directory into the next one.
        $stagingDir = sys_get_temp_dir().'/aps-connect-release-'.Str::random(16);

        try {
            $this->stage($stagingDir, $patterns);
            $this->installDependencies($stagingDir);

            if (! $skipNpm && is_file($stagingDir.'/package.json')) {
                $this->buildFrontend($stagingDir, $buildCommand);
            }

            if (! $skipObfuscation) {
                $this->obfuscate($stagingDir);
            }

            $zipPath = $outputPath ?? $this->defaultOutputPath($slug);
            $this->ensureDirectoryExists(dirname($zipPath));
            $this->zip($stagingDir, $zipPath, $patterns);

            return $zipPath;
        } finally {
            $this->remove($stagingDir);
        }
    }

    /**
     * @param  list<string>  $patterns
     */
    private function stage(string $stagingDir, array $patterns): void
    {
        $this->ensureDirectoryExists($stagingDir);

        $finder = (new Finder)->in($this->basePath)->files()->ignoreDotFiles(false)->ignoreVCS(false);

        foreach ($finder as $file) {
            // A symlink (e.g. `public/storage` -> `storage/app/public`,
            // created by `storage:link`) can point at a directory, which
            // copy() cannot handle — and its real target's own files are
            // already staged in their own right (storage/app isn't
            // excluded), so the link itself carries nothing new. Customers
            // run `storage:link` themselves after extracting, same as any
            // other Laravel deployment. Checked two ways: isLink() alone
            // isn't reliable for every reparse point Windows can produce
            // (NTFS junctions in particular), so also skip anything whose
            // resolved real path turns out to be a directory.
            if ($file->isLink() || is_dir($file->getRealPath())) {
                continue;
            }

            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if ($this->isExcluded($relativePath, $patterns)) {
                continue;
            }

            $target = $stagingDir.'/'.$relativePath;
            $this->ensureDirectoryExists(dirname($target));

            copy($file->getRealPath(), $target);
        }
    }

    private function installDependencies(string $stagingDir): void
    {
        $result = Process::path($stagingDir)->timeout(300)
            ->run('composer install --no-dev --optimize-autoloader --no-interaction');

        if ($result->failed()) {
            throw new RuntimeException("composer install --no-dev failed while packing the release:\n{$result->errorOutput()}");
        }
    }

    private function buildFrontend(string $stagingDir, ?string $buildCommand): void
    {
        $result = Process::path($stagingDir)->timeout(600)
            ->run($buildCommand ?? 'npm ci && npm run build');

        if ($result->failed()) {
            throw new RuntimeException("The frontend build failed while packing the release:\n{$result->errorOutput()}");
        }
    }

    /**
     * Deterrent against casual browsing/editing of the shipped source, not
     * real security — see PhpSourceObfuscator's own docblock for the full
     * reasoning and the exact ceiling of what this does and doesn't
     * protect. Every staged `.php` file except `vendor/**` — third-party
     * code, public anyway and far riskier to touch.
     */
    private function obfuscate(string $stagingDir): void
    {
        $obfuscator = new PhpSourceObfuscator;

        $finder = (new Finder)->in($stagingDir)->files()->name('*.php')->exclude('vendor');

        foreach ($finder as $file) {
            $obfuscator->obfuscateFile($file->getRealPath());
        }
    }

    /**
     * @param  list<string>  $patterns
     */
    private function zip(string $stagingDir, string $zipPath, array $patterns): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create the release archive at {$zipPath}.");
        }

        $finder = (new Finder)->in($stagingDir)->files()->ignoreDotFiles(false)->ignoreVCS(false);

        foreach ($finder as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            // Re-applied defensively: e.g. a composer package installed
            // from a VCS source can leave its own .git directory in vendor/.
            if ($this->isExcluded($relativePath, $patterns)) {
                continue;
            }

            $zip->addFile($file->getRealPath(), $relativePath);
        }

        $zip->close();
    }

    /**
     * @return list<string>
     */
    private function readApsIgnore(): array
    {
        $path = $this->basePath.'/.apsignore';

        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_values(array_filter(
            $lines,
            fn (string $line): bool => ! str_starts_with(trim($line), '#'),
        ));
    }

    /**
     * One glob pattern per line (no full gitignore syntax, no negation): a
     * pattern without a `/` matches any path segment (so `node_modules`
     * excludes it anywhere in the tree); a pattern containing `/` is
     * anchored to the project root and also excludes everything under it.
     *
     * @param  list<string>  $patterns
     */
    private function isExcluded(string $relativePath, array $patterns): bool
    {
        $segments = explode('/', $relativePath);

        foreach ($patterns as $pattern) {
            $pattern = rtrim(trim($pattern), '/');

            if ($pattern === '') {
                continue;
            }

            if (str_contains($pattern, '/')) {
                if (fnmatch($pattern, $relativePath, FNM_PATHNAME) || str_starts_with($relativePath, $pattern.'/')) {
                    return true;
                }

                continue;
            }

            foreach ($segments as $segment) {
                if (fnmatch($pattern, $segment)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function defaultOutputPath(string $slug): string
    {
        return $this->basePath.'/storage/app/aps-connect/releases/'.Str::slug($slug).'-'.date('Y-m-d-His').'.zip';
    }

    private function ensureDirectoryExists(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, recursive: true);
        }
    }

    private function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($dir);
    }
}
