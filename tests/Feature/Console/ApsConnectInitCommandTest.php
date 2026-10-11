<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Neocode\ApsConnect\Support\ApsCliSession;
use Neocode\ApsConnect\Support\ProjectConfigWriter;
use Neocode\ApsConnect\Support\ProjectInspector;

const APS_CONNECT_INIT_REMOTE = 'git@github.com:acme/my-app.git';

function apsConnectInitSoftwareResponse(): array
{
    return [
        'software' => ['id' => 7, 'token' => 'sw-token', 'name' => 'My App', 'type' => 'software'],
        'registra' => ['baseUrl' => 'https://registra.test/api', 'environment' => 'development', 'apiKey' => 'registra-key'],
        'signingSecret' => 'signing-secret',
        'projectId' => null,
    ];
}

function apsConnectInitModuleResponse(): array
{
    return [
        'project' => ['type' => 'module'],
        'module' => ['id' => 12, 'token' => 'mod-token', 'slug' => 'invoices', 'name' => 'Invoices'],
        'parentSoftware' => ['id' => 7, 'token' => 'sw-token', 'name' => 'My App'],
        'registra' => ['baseUrl' => 'https://registra.test/api', 'environment' => 'development', 'apiKey' => 'module-key'],
        'signingSecret' => 'module-secret',
        'projectId' => null,
    ];
}

function apsConnectInitReadJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true);
}

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/aps-connect-init-cmd-tests-'.bin2hex(random_bytes(8));
    mkdir($this->basePath, recursive: true);

    $this->sessionPath = $this->basePath.'/aps-cli-credentials.json';
    file_put_contents($this->sessionPath, json_encode([
        'appstation' => ['baseUrl' => 'https://app-station.test', 'accessToken' => 'session-token'],
    ]));

    app()->singleton(ApsCliSession::class, fn () => new ApsCliSession($this->sessionPath));
    app()->singleton(ProjectConfigWriter::class, fn () => new ProjectConfigWriter($this->basePath));
    app()->singleton(ProjectInspector::class, fn () => new ProjectInspector($this->basePath));

    putenv('APS_TOKEN');
    putenv('APPSTATION_BASE_URL');

    Process::fake(['*' => Process::result(APS_CONNECT_INIT_REMOTE."\n")]);
});

afterEach(function () {
    putenv('APS_TOKEN');
    putenv('APPSTATION_BASE_URL');

    foreach (glob($this->basePath.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
        is_file($file) && unlink($file);
    }

    @rmdir($this->basePath);
});

it('links an existing software and writes the project files like aps init', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares/7/link' => Http::response([
            'data' => ['id' => 7, 'name' => 'My App', 'slug' => 'my-app', 'status' => 'draft'],
            'registra_dev_sync_error' => null,
        ]),
        'app-station.test/api/v1/developer/registra/init' => Http::response(apsConnectInitSoftwareResponse()),
    ]);
    file_put_contents($this->basePath.'/composer.json', '{}');

    $this->artisan('aps-connect:init', ['--link' => true, '--software-id' => '7', '--no-interaction' => true])
        ->expectsOutputToContain('appstation.conf.json écrit.')
        ->expectsOutputToContain('.gitignore mis à jour.')
        ->assertSuccessful();

    $fingerprint = hash('sha256', APS_CONNECT_INIT_REMOTE);

    expect(apsConnectInitReadJson($this->basePath.'/appstation.conf.json'))->toBe([
        '$schema' => 'https://appstation.dev/schemas/appstation.conf.v1.json',
        'version' => 1,
        'type' => 'software',
        'environment' => 'development',
        'software' => ['token' => 'sw-token', 'name' => 'My App'],
        'api' => ['baseUrl' => 'https://registra.test/api'],
        'auth' => ['apiKeySource' => 'env', 'apiKeyEnvVar' => 'REGISTRA_API_KEY'],
        'integration' => ['detectedStack' => 'php'],
        'appstation' => ['baseUrl' => 'https://app-station.test', 'softwareId' => 7, 'projectFingerprint' => $fingerprint],
    ])->and(apsConnectInitReadJson($this->basePath.'/appstation.conf.local.json'))->toBe([
        'environment' => 'development',
        'auth' => ['apiKey' => 'registra-key'],
        'signingSecret' => 'signing-secret',
    ])->and(file_get_contents($this->basePath.'/.gitignore'))->toContain('appstation.conf.local.json');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/softwares/7/link')
        && $request->hasHeader('Authorization', 'Bearer session-token')
        && $request['project_fingerprint'] === $fingerprint
        && $request['force'] === false);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/registra/init')
        && $request['type'] === 'software' && $request['id'] === 7 && $request['environment'] === 'development');
});

it('creates a module under a parent software and links it', function () {
    Http::fake([
        'app-station.test/api/v1/developer/packages' => Http::response(['data' => ['id' => 12, 'name' => 'Invoices', 'slug' => 'invoices', 'status' => 'draft']], 201),
        'app-station.test/api/v1/developer/packages/12/link' => Http::response([
            'data' => ['id' => 12, 'name' => 'Invoices', 'slug' => 'invoices', 'status' => 'draft'],
            'registra_dev_sync_error' => 'sandbox unreachable',
        ]),
        'app-station.test/api/v1/developer/registra/init' => Http::response(apsConnectInitModuleResponse()),
    ]);

    $this->artisan('aps-connect:init', [
        '--create' => true,
        '--type' => 'module',
        '--parent-software-id' => '7',
        '--name' => 'Invoices',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Sync Registra dev : sandbox unreachable')
        ->assertSuccessful();

    $config = apsConnectInitReadJson($this->basePath.'/appstation.conf.json');

    expect($config['type'])->toBe('module')
        ->and($config['module'])->toBe(['token' => 'mod-token', 'slug' => 'invoices', 'name' => 'Invoices'])
        ->and($config['parentSoftware'])->toBe(['token' => 'sw-token', 'name' => 'My App'])
        ->and($config['appstation']['packageId'])->toBe(12)
        ->and($config['appstation']['parentSoftwareId'])->toBe(7);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/developer/packages')
        && $request['software_id'] === 7 && $request['name'] === 'Invoices');

    // A freshly created resource is always force-linked, like aps-cli.
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/packages/12/link') && $request['force'] === true);
});

it('lets the user pick the software to link across every page', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares?page=1' => Http::response([
            'data' => [['id' => 3, 'name' => 'Old App', 'slug' => 'old-app', 'status' => 'published']],
            'meta' => ['current_page' => 1, 'last_page' => 2],
        ]),
        'app-station.test/api/v1/developer/softwares?page=2' => Http::response([
            'data' => [['id' => 7, 'name' => 'My App', 'slug' => 'my-app', 'status' => 'draft']],
            'meta' => ['current_page' => 2, 'last_page' => 2],
        ]),
        'app-station.test/api/v1/developer/softwares/7/link' => Http::response([
            'data' => ['id' => 7, 'name' => 'My App', 'slug' => 'my-app', 'status' => 'draft'],
            'registra_dev_sync_error' => null,
        ]),
        'app-station.test/api/v1/developer/registra/init' => Http::response(apsConnectInitSoftwareResponse()),
    ]);

    $this->artisan('aps-connect:init')
        ->expectsChoice('Ce projet est-il un software ou un module ?', 'software', ['software', 'module'])
        ->expectsChoice(
            'Que souhaitez-vous faire ?',
            'Lier ce projet à un software/module App Station existant',
            ['Lier ce projet à un software/module App Station existant', 'Créer un nouveau software/module sur App Station'],
        )
        ->expectsChoice('Software App Station à lier ?', '#7 My App (draft)', ['#3 Old App (published)', '#7 My App (draft)'])
        ->assertSuccessful();

    expect(apsConnectInitReadJson($this->basePath.'/appstation.conf.json')['appstation']['softwareId'])->toBe(7);
});

it('refuses to steal a link from another repository without --force-link', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares/7/link' => Http::response(['message' => 'Already linked.'], 409),
    ]);

    $this->artisan('aps-connect:init', ['--link' => true, '--software-id' => '7', '--no-interaction' => true])
        ->expectsOutputToContain('Relancez avec --force-link')
        ->assertFailed();

    expect(is_file($this->basePath.'/appstation.conf.json'))->toBeFalse();
});

it('forces the link with --force-link', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares/7/link' => Http::response([
            'data' => ['id' => 7, 'name' => 'My App', 'slug' => 'my-app', 'status' => 'draft'],
            'registra_dev_sync_error' => null,
        ]),
        'app-station.test/api/v1/developer/registra/init' => Http::response(apsConnectInitSoftwareResponse()),
    ]);

    $this->artisan('aps-connect:init', ['--link' => true, '--software-id' => '7', '--force-link' => true, '--no-interaction' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/softwares/7/link') && $request['force'] === true);
});

it('uses APS_TOKEN with APPSTATION_BASE_URL over the aps login session', function () {
    Http::fake([
        'staging.test/api/v1/developer/softwares/7/link' => Http::response([
            'data' => ['id' => 7, 'name' => 'My App', 'slug' => 'my-app', 'status' => 'draft'],
            'registra_dev_sync_error' => null,
        ]),
        'staging.test/api/v1/developer/registra/init' => Http::response(apsConnectInitSoftwareResponse()),
    ]);
    putenv('APS_TOKEN=env-token');
    putenv('APPSTATION_BASE_URL=https://staging.test/');

    $this->artisan('aps-connect:init', ['--link' => true, '--software-id' => '7', '--no-interaction' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer env-token'));
    expect(apsConnectInitReadJson($this->basePath.'/appstation.conf.json')['appstation']['baseUrl'])->toBe('https://staging.test');
});

it('fails when APS_TOKEN is set without APPSTATION_BASE_URL', function () {
    putenv('APS_TOKEN=env-token');

    $this->artisan('aps-connect:init', ['--link' => true, '--software-id' => '7', '--no-interaction' => true])
        ->expectsOutputToContain('APPSTATION_BASE_URL est manquant')
        ->assertFailed();
});

it('fails when there is no publisher session', function () {
    unlink($this->sessionPath);

    $this->artisan('aps-connect:init', ['--link' => true, '--software-id' => '7', '--no-interaction' => true])
        ->expectsOutputToContain('exécutez "aps login"')
        ->assertFailed();
});

it('does not overwrite an existing appstation.conf.json non-interactively without --yes', function () {
    file_put_contents($this->basePath.'/appstation.conf.json', '{"keep": true}');

    $this->artisan('aps-connect:init', ['--link' => true, '--software-id' => '7', '--no-interaction' => true])
        ->expectsOutputToContain('Relancez avec --yes')
        ->assertFailed();

    expect(apsConnectInitReadJson($this->basePath.'/appstation.conf.json'))->toBe(['keep' => true]);
});

it('warns that a production key is inactive until the software is submitted', function () {
    $response = apsConnectInitSoftwareResponse();
    $response['registra']['environment'] = 'production';

    Http::fake([
        'app-station.test/api/v1/developer/softwares/7/link' => Http::response([
            'data' => ['id' => 7, 'name' => 'My App', 'slug' => 'my-app', 'status' => 'draft'],
            'registra_dev_sync_error' => null,
        ]),
        'app-station.test/api/v1/developer/registra/init' => Http::response($response),
    ]);

    $this->artisan('aps-connect:init', ['--link' => true, '--software-id' => '7', '--env' => 'production', '--no-interaction' => true])
        ->expectsOutputToContain('Statut actuel : "draft"')
        ->assertSuccessful();
});

it('rejects an invalid --type', function () {
    $this->artisan('aps-connect:init', ['--type' => 'plugin', '--no-interaction' => true])
        ->expectsOutputToContain('--type invalide')
        ->assertFailed();
});

it('creates a software with the fields App Station accepts', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares' => Http::response(['data' => ['id' => 7, 'name' => 'My App', 'slug' => 'my-app', 'status' => 'draft']], 201),
        'app-station.test/api/v1/developer/softwares/7/link' => Http::response([
            'data' => ['id' => 7, 'name' => 'My App', 'slug' => 'my-app', 'status' => 'draft'],
            'registra_dev_sync_error' => null,
        ]),
        'app-station.test/api/v1/developer/registra/init' => Http::response(apsConnectInitSoftwareResponse()),
    ]);

    $this->artisan('aps-connect:init', [
        '--create' => true,
        '--name' => 'My App',
        '--tagline' => 'Invoicing for small shops',
        '--license-type' => 'subscription',
        '--has-modules' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/developer/softwares')
        && $request->data() === ['name' => 'My App', 'tagline' => 'Invoicing for small shops', 'license_type' => 'subscription', 'has_modules' => true]);
});

it('asks for the name, tagline and license type when creating a software interactively', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares' => Http::response(['data' => ['id' => 7, 'name' => 'Shop', 'slug' => 'shop', 'status' => 'draft']], 201),
        'app-station.test/api/v1/developer/softwares/7/link' => Http::response([
            'data' => ['id' => 7, 'name' => 'Shop', 'slug' => 'shop', 'status' => 'draft'],
            'registra_dev_sync_error' => null,
        ]),
        'app-station.test/api/v1/developer/registra/init' => Http::response(apsConnectInitSoftwareResponse()),
    ]);

    $this->artisan('aps-connect:init', ['--type' => 'software', '--create' => true])
        ->expectsQuestion('Nom du software/module', 'Shop')
        ->expectsQuestion('Tagline', '')
        ->expectsChoice('Type de licence', 'paid', ['free', 'freemium', 'paid', 'open_source', 'subscription'])
        ->expectsConfirmation('Ce software propose-t-il des modules ?', 'no')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/developer/softwares')
        && $request->data() === ['name' => 'Shop', 'license_type' => 'paid', 'has_modules' => false]);
});

it('only offers softwares that propose modules as a new module parent', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares?page=1' => Http::response(['data' => [
            ['id' => 3, 'name' => 'No Modules', 'slug' => 'no-modules', 'status' => 'published', 'has_modules' => false],
            ['id' => 7, 'name' => 'My App', 'slug' => 'my-app', 'status' => 'published', 'has_modules' => true],
        ]]),
        'app-station.test/api/v1/developer/packages' => Http::response(['data' => ['id' => 12, 'name' => 'Invoices', 'slug' => 'invoices', 'status' => 'draft']], 201),
        'app-station.test/api/v1/developer/packages/12/link' => Http::response([
            'data' => ['id' => 12, 'name' => 'Invoices', 'slug' => 'invoices', 'status' => 'draft'],
            'registra_dev_sync_error' => null,
        ]),
        'app-station.test/api/v1/developer/registra/init' => Http::response(apsConnectInitModuleResponse()),
    ]);

    $this->artisan('aps-connect:init', ['--type' => 'module', '--create' => true, '--name' => 'Invoices'])
        ->expectsChoice('Software parent du module ?', '#7 My App (published)', ['#7 My App (published)'])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/developer/packages') && $request['software_id'] === 7);
});

it('explains how to enable modules when no software proposes them', function () {
    Http::fake([
        'app-station.test/api/v1/developer/softwares?page=1' => Http::response(['data' => [
            ['id' => 3, 'name' => 'No Modules', 'slug' => 'no-modules', 'status' => 'published', 'has_modules' => false],
        ]]),
    ]);

    $this->artisan('aps-connect:init', ['--type' => 'module', '--create' => true, '--name' => 'Invoices'])
        ->expectsOutputToContain('aps-connect:update --has-modules')
        ->assertFailed();
});

it('rejects an invalid --license-type', function () {
    $this->artisan('aps-connect:init', ['--create' => true, '--name' => 'My App', '--license-type' => 'gratis', '--no-interaction' => true])
        ->expectsOutputToContain('--license-type invalide')
        ->assertFailed();
});
