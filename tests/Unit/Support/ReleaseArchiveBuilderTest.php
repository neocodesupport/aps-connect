<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Neocode\ApsConnect\Support\ReleaseArchiveBuilder;

function apsConnectRemoveDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir.'/'.$item;
        is_dir($path) ? apsConnectRemoveDir($path) : unlink($path);
    }

    rmdir($dir);
}

/**
 * @return list<string>
 */
function apsConnectReadZipEntries(string $zipPath): array
{
    $zip = new ZipArchive;
    $zip->open($zipPath);

    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if ($name !== false) {
            $entries[] = $name;
        }
    }

    $zip->close();

    return $entries;
}

beforeEach(function () {
    $this->projectDir = sys_get_temp_dir().'/aps-connect-pack-tests-'.uniqid();
    mkdir($this->projectDir, recursive: true);

    file_put_contents($this->projectDir.'/composer.json', '{}');
    mkdir($this->projectDir.'/app', recursive: true);
    file_put_contents($this->projectDir.'/app/Foo.php', "<?php\n\nclass Foo {}\n");

    mkdir($this->projectDir.'/.git', recursive: true);
    file_put_contents($this->projectDir.'/.git/HEAD', 'ref: refs/heads/main');

    file_put_contents($this->projectDir.'/.env', 'APP_KEY=secret');
    file_put_contents($this->projectDir.'/appstation.conf.local.json', json_encode(['auth' => ['apiKey' => 'dev-secret']]));

    mkdir($this->projectDir.'/node_modules/some-package', recursive: true);
    file_put_contents($this->projectDir.'/node_modules/some-package/index.js', '');

    mkdir($this->projectDir.'/tests', recursive: true);
    file_put_contents($this->projectDir.'/tests/ExampleTest.php', "<?php\n");

    Process::fake();
});

afterEach(function () {
    apsConnectRemoveDir($this->projectDir);
});

it('excludes secrets, vcs metadata, and dev-only paths from the packed zip', function () {
    $builder = new ReleaseArchiveBuilder($this->projectDir);

    $zipPath = $builder->build('My Software', outputPath: $this->projectDir.'/out.zip', skipNpm: true, skipObfuscation: true);

    $entries = apsConnectReadZipEntries($zipPath);

    expect($entries)->toContain('composer.json')->toContain('app/Foo.php');
    expect($entries)->not->toContain('.git/HEAD');
    expect($entries)->not->toContain('.env');
    expect($entries)->not->toContain('appstation.conf.local.json');
    expect($entries)->not->toContain('node_modules/some-package/index.js');
    expect($entries)->not->toContain('tests/ExampleTest.php');
});

it('excludes internal/dev-context docs by default', function () {
    file_put_contents($this->projectDir.'/README.md', '# Internal notes');
    file_put_contents($this->projectDir.'/CONTRIBUTING.md', 'contrib');
    file_put_contents($this->projectDir.'/CHANGELOG.md', 'changelog');
    file_put_contents($this->projectDir.'/AGENTS.md', 'agent context');
    file_put_contents($this->projectDir.'/CLAUDE.md', 'claude context');
    file_put_contents($this->projectDir.'/boost.json', '{}');
    mkdir($this->projectDir.'/docs', recursive: true);
    file_put_contents($this->projectDir.'/docs/architecture.md', 'business logic notes');

    $builder = new ReleaseArchiveBuilder($this->projectDir);
    $zipPath = $builder->build('My Software', outputPath: $this->projectDir.'/out.zip', skipNpm: true, skipObfuscation: true);

    $entries = apsConnectReadZipEntries($zipPath);

    expect($entries)->not->toContain('README.md');
    expect($entries)->not->toContain('CONTRIBUTING.md');
    expect($entries)->not->toContain('CHANGELOG.md');
    expect($entries)->not->toContain('AGENTS.md');
    expect($entries)->not->toContain('CLAUDE.md');
    expect($entries)->not->toContain('boost.json');
    expect($entries)->not->toContain('docs/architecture.md');
});

it('excludes a populated sqlite database and stray temp files by default', function () {
    mkdir($this->projectDir.'/database', recursive: true);
    file_put_contents($this->projectDir.'/database/database.sqlite', 'binary-sqlite-bytes');
    file_put_contents($this->projectDir.'/bootstrap-cache.tmp', 'stray');

    $builder = new ReleaseArchiveBuilder($this->projectDir);
    $zipPath = $builder->build('My Software', outputPath: $this->projectDir.'/out.zip', skipNpm: true, skipObfuscation: true);

    $entries = apsConnectReadZipEntries($zipPath);

    expect($entries)->not->toContain('database/database.sqlite');
    expect($entries)->not->toContain('bootstrap-cache.tmp');
});

it('honors extra excludes declared in .apsignore', function () {
    file_put_contents($this->projectDir.'/secret-notes.md', 'shh');
    file_put_contents($this->projectDir.'/.apsignore', "secret-notes.md\n");

    $builder = new ReleaseArchiveBuilder($this->projectDir);
    $zipPath = $builder->build('My Software', outputPath: $this->projectDir.'/out.zip', skipNpm: true, skipObfuscation: true);

    expect(apsConnectReadZipEntries($zipPath))->not->toContain('secret-notes.md');
});

it('never lets .apsignore content override the hardcoded secret excludes', function () {
    file_put_contents($this->projectDir.'/.apsignore', "!.env\n!appstation.conf.local.json\n");

    $builder = new ReleaseArchiveBuilder($this->projectDir);
    $zipPath = $builder->build('My Software', outputPath: $this->projectDir.'/out.zip', skipNpm: true, skipObfuscation: true);

    $entries = apsConnectReadZipEntries($zipPath);

    expect($entries)->not->toContain('.env');
    expect($entries)->not->toContain('appstation.conf.local.json');
});

it('obfuscates staged php source by default, but never touches vendor', function () {
    mkdir($this->projectDir.'/vendor/acme', recursive: true);
    file_put_contents($this->projectDir.'/vendor/acme/Lib.php', "<?php\n\n/** Third-party docblock. */\nclass Lib {}\n");
    file_put_contents($this->projectDir.'/app/Foo.php', "<?php\n\n/** Marker docblock. */\nclass Foo {}\n");

    $builder = new ReleaseArchiveBuilder($this->projectDir);
    $zipPath = $builder->build('My Software', outputPath: $this->projectDir.'/out.zip', skipNpm: true);

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->getFromName('app/Foo.php'))->not->toContain('Marker docblock');
    expect($zip->getFromName('vendor/acme/Lib.php'))->toContain('Third-party docblock');

    $zip->close();
});

it('skips obfuscation when requested', function () {
    file_put_contents($this->projectDir.'/app/Foo.php', "<?php\n\n/** Marker docblock. */\nclass Foo {}\n");

    $builder = new ReleaseArchiveBuilder($this->projectDir);
    $zipPath = $builder->build('My Software', outputPath: $this->projectDir.'/out.zip', skipNpm: true, skipObfuscation: true);

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->getFromName('app/Foo.php'))->toContain('Marker docblock');

    $zip->close();
});

it('throws when composer install fails', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'boom')]);

    $builder = new ReleaseArchiveBuilder($this->projectDir);

    expect(fn () => $builder->build('My Software', outputPath: $this->projectDir.'/out.zip', skipNpm: true, skipObfuscation: true))
        ->toThrow(RuntimeException::class);
});
