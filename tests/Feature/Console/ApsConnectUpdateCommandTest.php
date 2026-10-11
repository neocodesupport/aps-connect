<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Neocode\ApsConnect\Support\ApsCliSession;
use Neocode\ApsConnect\Support\ProjectConfigReader;

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/aps-connect-update-tests-'.bin2hex(random_bytes(8));
    mkdir($this->basePath, recursive: true);

    file_put_contents($this->basePath.'/appstation.conf.json', json_encode([
        'type' => 'software',
        'software' => ['token' => 'sw-tok', 'name' => 'My App'],
        'appstation' => ['softwareId' => 7, 'projectFingerprint' => 'fp'],
    ]));

    $this->sessionPath = $this->basePath.'/aps-cli-credentials.json';
    file_put_contents($this->sessionPath, json_encode([
        'appstation' => ['baseUrl' => 'https://app-station.test', 'accessToken' => 'session-token'],
    ]));

    app()->singleton(ApsCliSession::class, fn () => new ApsCliSession($this->sessionPath));
    app()->singleton(ProjectConfigReader::class, fn () => new ProjectConfigReader($this->basePath));

    putenv('APS_TOKEN');
    putenv('APPSTATION_BASE_URL');
});

afterEach(function () {
    foreach (glob($this->basePath.'/*') ?: [] as $file) {
        unlink($file);
    }

    @rmdir($this->basePath);
});

function apsConnectUseModuleConfig(string $basePath): void
{
    file_put_contents($basePath.'/appstation.conf.json', json_encode([
        'type' => 'module',
        'module' => ['token' => 'mod-tok', 'slug' => 'invoices', 'name' => 'Invoices'],
        'appstation' => ['packageId' => 12, 'projectFingerprint' => 'fp'],
    ]));
}

it('patches only the fields passed', function () {
    Http::fake(['app-station.test/api/v1/publisher/softwares/7' => Http::response(['data' => ['id' => 7, 'name' => 'Shop Pro']])]);

    $this->artisan('aps-connect:update', [
        '--name' => 'Shop Pro',
        '--tagline' => 'Invoicing for shops',
        '--price-per-day' => '150',
        '--lifetime-price' => '0',
        '--trial-period-days' => '14',
    ])
        ->expectsOutputToContain('Software mis à jour : Shop Pro.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->hasHeader('Authorization', 'Bearer session-token')
        && $request->data() === [
            'name' => 'Shop Pro',
            'tagline' => 'Invoicing for shops',
            'price_per_day_xof' => 150,
            'lifetime_price_xof' => 0,
            'has_trial_mode' => true,
            'trial_period_days' => 14,
        ]);
});

it('disables the trial', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 7, 'name' => 'My App']])]);

    $this->artisan('aps-connect:update', ['--disable-trial' => true])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->data() === ['has_trial_mode' => false]);
});

it('updates a module name and description', function () {
    apsConnectUseModuleConfig($this->basePath);
    Http::fake(['app-station.test/api/v1/publisher/packages/12' => Http::response(['data' => ['id' => 12, 'name' => 'Factures']])]);

    $this->artisan('aps-connect:update', ['--name' => 'Factures', '--description' => 'Gestion des factures.'])
        ->expectsOutputToContain('Module mis à jour : Factures.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->data() === ['name' => 'Factures', 'description' => 'Gestion des factures.']);
});

it('refuses software-only options on a module', function () {
    apsConnectUseModuleConfig($this->basePath);
    Http::fake();

    $this->artisan('aps-connect:update', ['--tagline' => 'x', '--disable-trial' => true])
        ->expectsOutputToContain('--tagline, --disable-trial non disponible pour un module')
        ->assertFailed();

    Http::assertNothingSent();
});

it('rejects invalid values before calling App Station', function (array $options, string $message) {
    Http::fake();

    $this->artisan('aps-connect:update', $options)
        ->expectsOutputToContain($message)
        ->assertFailed();

    Http::assertNothingSent();
})->with([
    'no field' => [[], 'Aucun champ à mettre à jour'],
    'name too short' => [['--name' => 'A'], 'entre 2 et 120 caractères'],
    'tagline too long' => [['--tagline' => str_repeat('a', 181)], 'ne doit pas dépasser 180'],
    'negative price' => [['--price-per-day' => '-5'], 'entier positif ou nul'],
    'trial out of range' => [['--trial-period-days' => '400'], 'entre 1 et 365'],
    'contradictory trial' => [['--trial-period-days' => '7', '--disable-trial' => true], 'contradictoires'],
]);

it('shows App Station validation errors', function () {
    Http::fake(['*' => Http::response(['message' => 'Invalid.', 'errors' => ['name' => ['Ce nom est déjà pris.']]], 422)]);

    $this->artisan('aps-connect:update', ['--name' => 'Taken'])
        ->expectsOutputToContain('name: Ce nom est déjà pris.')
        ->assertFailed();
});
