<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Neocode\ApsConnect\ApsConnect;
use Neocode\ApsConnect\Data\AppStationCredentials;
use Neocode\ApsConnect\Data\RegistraCredentials;
use Neocode\ApsConnect\Exceptions\AppStationResourceNotFoundException;
use Neocode\ApsConnect\Http\AppStationClient;
use Neocode\ApsConnect\Http\RegistraClient;
use Neocode\ApsConnect\Support\ProjectConfigReader;

beforeEach(function () {
    // See ApsConnectServiceProviderTest for why this rebinds to an isolated
    // temp directory. The base urls have no config/env fallback, so both
    // must be declared explicitly here.
    $this->registraBaseUrl = 'https://registra.neocode.ci/api';
    $this->appStationBaseUrl = 'https://app-station.neocode.ci';

    $this->basePath = sys_get_temp_dir().'/aps-connect-tests-'.uniqid();
    mkdir($this->basePath, recursive: true);
    file_put_contents($this->basePath.'/appstation.conf.json', json_encode([
        'environment' => 'production',
        'api' => ['baseUrl' => $this->registraBaseUrl],
        'appstation' => ['baseUrl' => $this->appStationBaseUrl],
    ]));
    file_put_contents($this->basePath.'/appstation.conf.local.json', json_encode([
        'auth' => ['apiKey' => 'secret-key'],
    ]));

    app()->singleton(RegistraCredentials::class, fn () => (new ProjectConfigReader($this->basePath))->credentials());
    app()->singleton(AppStationCredentials::class, fn () => (new ProjectConfigReader($this->basePath))->appStationCredentials(app(RegistraCredentials::class)));
});

afterEach(function () {
    foreach (glob($this->basePath.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->basePath);
});

it('verifies a licence', function () {
    Http::fake(['*/licences/verify' => Http::response([
        'success' => true,
        'active' => true,
        'status_active' => true,
        'not_expired' => true,
        'reason' => null,
        'licence_key' => 'LIC-1',
        'status' => 'active',
        'expires_at' => '2026-08-15T00:00:00+00:00',
        'remaining_days' => 30,
        'modules' => [
            ['slug' => 'reports', 'status' => 'active', 'active' => true, 'expires_at' => '2026-08-15T00:00:00+00:00', 'remaining_days' => 30, 'reason' => null],
        ],
        'message' => 'ok',
    ])]);

    $status = app(ApsConnect::class)->verifyLicence('LIC-1');

    expect($status->licenceKey)->toBe('LIC-1');
    expect($status->active)->toBeTrue();
    expect($status->statusActive)->toBeTrue();
    expect($status->notExpired)->toBeTrue();
    expect($status->reason)->toBeNull();
    expect($status->status)->toBe('active');
    expect($status->expiresAt->toAtomString())->toBe('2026-08-15T00:00:00+00:00');
    expect($status->remainingDays)->toBe(30);
    expect($status->message)->toBe('ok');
    expect($status->modules)->toHaveCount(1);
    expect($status->modules[0]->slug)->toBe('reports');
    expect($status->modules[0]->active)->toBeTrue();
    expect($status->modules[0]->expiresAt->toAtomString())->toBe('2026-08-15T00:00:00+00:00');

    Http::assertSent(fn ($request) => $request['licence_key'] === 'LIC-1');
});

it('verifies a module licence', function () {
    Http::fake(['*/licences/verify-module' => Http::response([
        'success' => true,
        'licence_key' => 'LIC-1',
        'module_slug' => 'reports',
        'mother' => [
            'active' => true,
            'status_active' => true,
            'not_expired' => true,
            'reason' => null,
            'status' => 'active',
            'expires_at' => '2026-08-15T00:00:00+00:00',
            'remaining_days' => 30,
        ],
        'module' => ['slug' => 'reports', 'status' => 'active', 'active' => true, 'expires_at' => '2026-08-15T00:00:00+00:00', 'remaining_days' => 30, 'reason' => null],
        'message' => 'ok',
    ])]);

    $verification = app(ApsConnect::class)->verifyModuleLicence('LIC-1', 'reports');

    expect($verification->licenceKey)->toBe('LIC-1');
    expect($verification->moduleSlug)->toBe('reports');
    expect($verification->mother->licenceKey)->toBe('LIC-1');
    expect($verification->mother->active)->toBeTrue();
    expect($verification->mother->modules)->toBe([]);
    expect($verification->module->slug)->toBe('reports');
    expect($verification->module->active)->toBeTrue();
    expect($verification->message)->toBe('ok');

    Http::assertSent(fn ($request) => $request['licence_key'] === 'LIC-1' && $request['module_slug'] === 'reports');
});

it('subscribes a licence with a customer and device', function () {
    Http::fake(['*/subscribe' => Http::response([
        'success' => true,
        'active' => true,
        'status_active' => true,
        'not_expired' => true,
        'reason' => null,
        'licence_key' => 'LIC-1',
        'status' => 'active',
        'expires_at' => '2026-08-15T00:00:00+00:00',
        'remaining_days' => 30,
        'modules' => [],
        'message' => 'ok',
        'usage_period' => ['value' => 1, 'unit' => 'year', 'label' => 'Année'],
        'customer' => [
            'id' => 1, 'firstname' => 'Jane', 'lastname' => 'Doe', 'email' => 'jane@example.com',
            'status' => 'active', 'phone' => null, 'address' => null, 'software_key' => null,
        ],
        'device' => [
            'id' => 1, 'uuid' => 'dev-1', 'type' => 'desktop', 'name' => 'PC', 'os' => 'Windows',
            'browser' => 'Chrome', 'last_active_at' => '2026-08-15T00:00:00+00:00',
        ],
    ])]);

    $result = app(ApsConnect::class)->subscribe('LIC-1', ['email' => 'jane@example.com'], ['uuid' => 'dev-1']);

    expect($result->licence->licenceKey)->toBe('LIC-1');
    expect($result->licence->active)->toBeTrue();
    expect($result->usagePeriod->value)->toBe(1);
    expect($result->usagePeriod->unit)->toBe('year');
    expect($result->customer->id)->toBe(1);
    expect($result->customer->email)->toBe('jane@example.com');
    expect($result->device->uuid)->toBe('dev-1');
    expect($result->device->lastActiveAt->toAtomString())->toBe('2026-08-15T00:00:00+00:00');

    Http::assertSent(fn ($request) => $request['licence_key'] === 'LIC-1'
        && $request['customer']['email'] === 'jane@example.com'
        && $request['device']['uuid'] === 'dev-1');
});

it('omits the device field entirely when subscribing without a device', function () {
    Http::fake(['*/subscribe' => Http::response([
        'success' => true,
        'active' => true,
        'status_active' => true,
        'not_expired' => true,
        'reason' => null,
        'licence_key' => 'LIC-1',
        'status' => 'active',
        'expires_at' => null,
        'remaining_days' => null,
        'modules' => [],
        'message' => null,
        'usage_period' => null,
        'customer' => ['email' => 'jane@example.com'],
        'device' => null,
    ])]);

    app(ApsConnect::class)->subscribe('LIC-1', ['email' => 'jane@example.com']);

    Http::assertSent(fn ($request) => ! array_key_exists('device', $request->data()));
});

it('issues a trial licence', function () {
    Http::fake(['*/licences/trial' => Http::response([
        'success' => true,
        'message' => 'ok',
        'licence_key' => 'LIC-TRIAL',
        'trial_period_days' => 14,
        'must_activate_before_at' => '2026-08-29T00:00:00+00:00',
        'usage_period' => ['value' => 1, 'unit' => 'month', 'label' => 'Mois'],
        'customer' => ['email' => 'trial@example.com'],
    ])]);

    $trial = app(ApsConnect::class)->issueTrial(['email' => 'trial@example.com']);

    expect($trial->licenceKey)->toBe('LIC-TRIAL');
    expect($trial->trialPeriodDays)->toBe(14);
    expect($trial->mustActivateBeforeAt->toAtomString())->toBe('2026-08-29T00:00:00+00:00');
    expect($trial->usagePeriod->unit)->toBe('month');
    expect($trial->customerEmail)->toBe('trial@example.com');
});

it('issues a module trial licence', function () {
    Http::fake(['*/modules/trial' => Http::response([
        'success' => true,
        'message' => 'ok',
        'module_licence_key' => 'MOD-TRIAL',
        'module_slug' => 'reports',
        'trial_period_days' => 14,
        'usage_period' => ['value' => 1, 'unit' => 'month', 'label' => 'Mois'],
        'customer' => ['email' => 'trial@example.com'],
    ])]);

    $trial = app(ApsConnect::class)->issueModuleTrial('reports', ['email' => 'trial@example.com']);

    expect($trial->moduleLicenceKey)->toBe('MOD-TRIAL');
    expect($trial->moduleSlug)->toBe('reports');
    expect($trial->trialPeriodDays)->toBe(14);
    expect($trial->customerEmail)->toBe('trial@example.com');

    Http::assertSent(fn ($request) => $request['module_slug'] === 'reports');
});

it('verifies a standalone module licence', function () {
    Http::fake(['*/standalone-module-licences/verify' => Http::response([
        'success' => true,
        'module_licence_key' => 'SM-1',
        'module_slug' => 'reports',
        'status' => 'pending_attachment',
        'active' => false,
        'attached' => false,
        'mother_licence_key' => null,
        'reason' => 'pending_attachment',
        'expires_at' => null,
        'message' => 'ok',
    ])]);

    $licence = app(ApsConnect::class)->verifyStandaloneModuleLicence('SM-1');

    expect($licence->moduleLicenceKey)->toBe('SM-1');
    expect($licence->status)->toBe('pending_attachment');
    expect($licence->active)->toBeFalse();
    expect($licence->attached)->toBeFalse();
    expect($licence->motherLicenceKey)->toBeNull();
    expect($licence->expiresAt)->toBeNull();
});

it('activates a standalone module licence', function () {
    Http::fake(['*/standalone-module-licences/activate' => Http::response([
        'success' => true,
        'message' => 'ok',
        'module_licence_key' => 'SM-1',
        'status' => 'pending_attachment',
        'expires_at' => null,
        'usage_period' => null,
        'customer' => ['id' => 2, 'email' => 'cust@example.com'],
        'already' => false,
    ])]);

    $activation = app(ApsConnect::class)->activateStandaloneModuleLicence('SM-1', ['email' => 'cust@example.com']);

    expect($activation->moduleLicenceKey)->toBe('SM-1');
    expect($activation->usagePeriod)->toBeNull();
    expect($activation->customer->id)->toBe(2);
    expect($activation->customer->email)->toBe('cust@example.com');
    expect($activation->already)->toBeFalse();
});

it('attaches a standalone module licence to a mother licence', function () {
    Http::fake(['*/standalone-module-licences/attach' => Http::response([
        'success' => true,
        'message' => 'ok',
        'mother_licence_key' => 'LIC-1',
        'modules' => [
            ['slug' => 'reports', 'status' => 'active', 'active' => true, 'expires_at' => null, 'remaining_days' => null, 'reason' => null],
        ],
    ])]);

    $attachment = app(ApsConnect::class)->attachStandaloneModuleLicence('SM-1', 'LIC-1');

    expect($attachment->motherLicenceKey)->toBe('LIC-1');
    expect($attachment->modules)->toHaveCount(1);
    expect($attachment->modules[0]->slug)->toBe('reports');

    Http::assertSent(fn ($request) => $request['module_licence_key'] === 'SM-1' && $request['mother_licence_key'] === 'LIC-1');
});

it('looks up a licence by customer email and device', function () {
    Http::fake(['*/licences/lookup-by-customer-device' => Http::response([
        'success' => true,
        'active' => true,
        'status_active' => true,
        'not_expired' => true,
        'reason' => null,
        'licence_key' => 'LIC-1',
        'status' => 'active',
        'expires_at' => null,
        'remaining_days' => null,
        'modules' => [],
        'message' => 'ok',
        'usage_period' => null,
        'customer' => ['email' => 'jane@example.com'],
        'device' => null,
    ])]);

    $result = app(ApsConnect::class)->lookupByCustomerDevice('jane@example.com', 'dev-1');

    expect($result->licence->licenceKey)->toBe('LIC-1');
    expect($result->customer->email)->toBe('jane@example.com');

    Http::assertSent(fn ($request) => $request['email'] === 'jane@example.com' && $request['device_uuid'] === 'dev-1');
});

it('fetches the connected software identity', function () {
    Http::fake(['*/software/me' => Http::response([
        'success' => true,
        'data' => [
            'token' => 'tok',
            'key' => 'KEY',
            'name' => 'My Software',
            'slug' => 'my-software',
            'environment' => 'production',
            'has_modules' => true,
            'has_api_key' => true,
            'api_key_fingerprint' => 'abc123',
            'linked_production_token' => null,
            'has_previous_api_key_grace_period' => false,
            'api_key_previous_expires_at' => null,
        ],
    ])]);

    $identity = app(ApsConnect::class)->me();

    expect($identity->token)->toBe('tok');
    expect($identity->name)->toBe('My Software');
    expect($identity->environment)->toBe('production');
    expect($identity->hasModules)->toBeTrue();
    expect($identity->hasApiKey)->toBeTrue();
    expect($identity->apiKeyFingerprint)->toBe('abc123');
    expect($identity->hasPreviousApiKeyGracePeriod)->toBeFalse();
    expect($identity->apiKeyPreviousExpiresAt)->toBeNull();
});

it('routes licence verification to the sandbox endpoint in the development environment', function () {
    // Built directly with development credentials rather than through the
    // container: environment is only ever sourced from appstation.conf.json
    // now (see ProjectConfigReaderTest), so there is no config() shortcut
    // to force "development" for a full container resolution here.
    $registraBaseUrl = $this->registraBaseUrl;

    $apsConnect = new ApsConnect(new RegistraClient(
        new RegistraCredentials('dev-secret-key', $registraBaseUrl, 'development'),
        app(Factory::class),
    ), new AppStationClient(
        new AppStationCredentials('dev-secret-key', $this->appStationBaseUrl),
        app(Factory::class),
    ));

    Http::fake(['*' => Http::response([
        'success' => true, 'active' => true, 'status_active' => true, 'not_expired' => true, 'reason' => null,
        'licence_key' => 'APS-DEV-ACTIVE', 'status' => 'active', 'expires_at' => null, 'remaining_days' => null,
        'modules' => [], 'message' => null,
    ])]);

    $apsConnect->verifyLicence('APS-DEV-ACTIVE');

    Http::assertSent(fn ($request) => $request->url() === "{$registraBaseUrl}/sandbox/licences/verify");
});

it('never routes trial issuance to the sandbox endpoint, even in the development environment', function () {
    $registraBaseUrl = $this->registraBaseUrl;

    $apsConnect = new ApsConnect(new RegistraClient(
        new RegistraCredentials('dev-secret-key', $registraBaseUrl, 'development'),
        app(Factory::class),
    ), new AppStationClient(
        new AppStationCredentials('dev-secret-key', $this->appStationBaseUrl),
        app(Factory::class),
    ));

    Http::fake(['*' => Http::response([
        'success' => true, 'message' => 'ok', 'licence_key' => 'LIC-TRIAL', 'trial_period_days' => 14,
        'must_activate_before_at' => null, 'usage_period' => ['value' => 1, 'unit' => 'month', 'label' => 'Mois'],
        'customer' => ['email' => 'trial@example.com'],
    ])]);

    $apsConnect->issueTrial(['email' => 'trial@example.com']);

    Http::assertSent(fn ($request) => $request->url() === "{$registraBaseUrl}/licences/trial");
});

it('registers a software instance', function () {
    Http::fake(['*/integrations/software/instances/register' => Http::response([
        'instance' => [
            'id' => 1,
            'label' => 'Client ACME',
            'licence_key_mask' => 'LIC-•••-1',
            'client_reference' => 'tenant-1',
            'status' => 'active',
            'last_seen_at' => null,
            'revoked_at' => null,
            'registered_at' => '2026-08-15T00:00:00+00:00',
        ],
        'api_key' => 'instance-secret-key',
    ], 201)]);

    $registration = app(ApsConnect::class)->registerSoftwareInstance('LIC-1', 'tenant-1', 'Client ACME');

    expect($registration->apiKey)->toBe('instance-secret-key');
    expect($registration->instance->id)->toBe(1);
    expect($registration->instance->label)->toBe('Client ACME');
    expect($registration->instance->clientReference)->toBe('tenant-1');
    expect($registration->instance->status)->toBe('active');
    expect($registration->instance->registeredAt->toAtomString())->toBe('2026-08-15T00:00:00+00:00');

    Http::assertSent(fn ($request) => $request['licence_key'] === 'LIC-1' && $request['client_reference'] === 'tenant-1');
});

it('downloads a package release', function () {
    Http::fake(['*/integrations/software/packages/42/download' => Http::response([
        'url' => 'https://cdn.test/package-42.zip',
        'expires_at' => '2026-08-15T00:10:00+00:00',
        'checksum' => 'sha256:abc123',
    ])]);

    $download = app(ApsConnect::class)->downloadPackage('instance-secret-key', 42, 'MOD-1');

    expect($download->url)->toBe('https://cdn.test/package-42.zip');
    expect($download->expiresAt->toAtomString())->toBe('2026-08-15T00:10:00+00:00');
    expect($download->checksum)->toBe('sha256:abc123');

    Http::assertSent(fn ($request) => $request->hasHeader('X-Software-Api-Key', 'instance-secret-key') && $request['module_licence_key'] === 'MOD-1');
});

it('checks for an update when one is available', function () {
    Http::fake(['*/integrations/software/updates/check' => Http::response([
        'update_available' => true,
        'latest_version' => '2.0.0',
        'release' => [
            'id' => 7,
            'version' => '2.0.0',
            'platform' => 'windows',
            'channel' => 'stable',
            'release_notes' => 'Bug fixes',
            'checksum' => 'sha256:def456',
            'signature' => 'sig-1',
            'file_size' => 1024,
            'is_yanked' => false,
            'published_at' => '2026-08-10T00:00:00+00:00',
        ],
        'url' => 'https://cdn.test/release-2.0.0.zip',
        'expires_at' => '2026-08-15T00:10:00+00:00',
        'checksum' => 'sha256:def456',
        'signature' => 'sig-1',
    ])]);

    $result = app(ApsConnect::class)->checkForUpdate('instance-secret-key', '1.0.0', 'windows', 'beta', 'stable');

    expect($result->updateAvailable)->toBeTrue();
    expect($result->latestVersion)->toBe('2.0.0');
    expect($result->release->version)->toBe('2.0.0');
    expect($result->release->isYanked)->toBeFalse();
    expect($result->url)->toBe('https://cdn.test/release-2.0.0.zip');
    expect($result->checksum)->toBe('sha256:def456');
    expect($result->signature)->toBe('sig-1');

    Http::assertSent(fn ($request) => $request['current_version'] === '1.0.0'
        && $request['platform'] === 'windows'
        && $request['min_stability'] === 'beta'
        && $request['current_channel'] === 'stable');
});

it('checks for an update when none is available', function () {
    Http::fake(['*/integrations/software/updates/check' => Http::response([
        'update_available' => false,
        'latest_version' => '1.0.0',
    ])]);

    $result = app(ApsConnect::class)->checkForUpdate('instance-secret-key', '1.0.0');

    expect($result->updateAvailable)->toBeFalse();
    expect($result->latestVersion)->toBe('1.0.0');
    expect($result->release)->toBeNull();
    expect($result->url)->toBeNull();
});

it('lists marketplace softwares', function () {
    Http::fake(['*/api/v1/softwares*' => Http::response([
        'data' => [[
            'id' => 1, 'name' => 'Corptrix', 'slug' => 'corptrix', 'tagline' => null, 'description' => null,
            'logo_url' => null, 'banner_url' => null, 'license_type' => null, 'acquisition_mode' => 'subscription',
            'status' => 'published', 'is_featured' => false, 'has_modules' => false, 'price_per_day_xof' => null,
            'lifetime_price_xof' => null, 'downloads_count' => 0, 'rating_avg' => null, 'rating_count' => 0,
            'publisher' => null, 'categories' => [], 'tags' => [],
        ]],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 12, 'total' => 1],
    ])]);

    $softwares = app(ApsConnect::class)->listSoftwares(['category_id' => 2]);

    expect($softwares->items)->toHaveCount(1);
    expect($softwares->items[0]->slug)->toBe('corptrix');

    Http::assertSent(fn ($request) => $request['category_id'] === 2);
});

it('fetches a single marketplace software by slug', function () {
    Http::fake(['*/api/v1/softwares/corptrix' => Http::response([
        'id' => 1, 'name' => 'Corptrix', 'slug' => 'corptrix', 'tagline' => null, 'description' => null,
        'logo_url' => null, 'banner_url' => null, 'license_type' => null, 'acquisition_mode' => 'subscription',
        'status' => 'published', 'is_featured' => false, 'has_modules' => false, 'price_per_day_xof' => null,
        'lifetime_price_xof' => null, 'downloads_count' => 0, 'rating_avg' => null, 'rating_count' => 0,
        'publisher' => null, 'categories' => [], 'tags' => [],
    ])]);

    $software = app(ApsConnect::class)->getSoftware('corptrix');

    expect($software->slug)->toBe('corptrix');
});

it('throws AppStationResourceNotFoundException when a software does not exist', function () {
    Http::fake(['*/api/v1/softwares/missing' => Http::response(['message' => 'Logiciel introuvable.'], 404)]);

    expect(fn () => app(ApsConnect::class)->getSoftware('missing'))
        ->toThrow(AppStationResourceNotFoundException::class, 'Logiciel introuvable.');
});

it('lists a software release history', function () {
    Http::fake(['*/api/v1/softwares/corptrix/releases*' => Http::response([
        'data' => [[
            'id' => 7,
            'version' => '2.0.0',
            'platform' => 'windows',
            'channel' => 'stable',
            'release_notes' => 'Bug fixes',
            'checksum' => 'sha256:abc',
            'signature' => null,
            'file_size' => 1024,
            'is_yanked' => false,
            'published_at' => '2026-08-10T00:00:00+00:00',
        ]],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1],
    ])]);

    $releases = app(ApsConnect::class)->getSoftwareReleases('corptrix');

    expect($releases->items)->toHaveCount(1);
    expect($releases->items[0]->version)->toBe('2.0.0');

    Http::assertSent(fn ($request) => $request->url() === $this->appStationBaseUrl.'/api/v1/softwares/corptrix/releases?page=1');
});

it('lists the packages published under a software', function () {
    Http::fake(['*/api/v1/softwares/corptrix/packages*' => Http::response([
        'data' => [[
            'id' => 4,
            'name' => 'Reports Pro',
            'slug' => 'reports-pro',
            'description' => null,
            'icon_url' => null,
            'type' => 'module',
            'is_official' => true,
            'is_featured' => false,
            'is_included_in_base' => false,
            'acquisition_mode' => 'one_time',
            'price_per_day_xof' => null,
            'lifetime_price_xof' => 15000,
            'has_trial_mode' => true,
            'trial_period_days' => 14,
            'status' => 'published',
            'downloads_count' => 40,
            'rating_avg' => null,
            'rating_count' => 0,
            'publisher' => ['id' => 3, 'name' => 'Neocode', 'slug' => 'neocode', 'description' => null, 'logo_url' => null, 'website' => null, 'is_verified' => true, 'status' => 'active'],
            'software' => ['id' => 1, 'name' => 'Corptrix', 'slug' => 'corptrix', 'tagline' => null, 'description' => null, 'logo_url' => null, 'banner_url' => null, 'license_type' => null, 'acquisition_mode' => 'subscription', 'status' => 'published', 'is_featured' => false, 'has_modules' => true, 'price_per_day_xof' => null, 'lifetime_price_xof' => null, 'downloads_count' => 0, 'rating_avg' => null, 'rating_count' => 0],
        ]],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 12, 'total' => 1],
    ])]);

    $packages = app(ApsConnect::class)->getSoftwarePackages('corptrix');

    expect($packages->items)->toHaveCount(1);
    expect($packages->items[0]->slug)->toBe('reports-pro');
    expect($packages->items[0]->software->slug)->toBe('corptrix');
    expect($packages->items[0]->software->publisher)->toBeNull();
    expect($packages->items[0]->software->categories)->toBe([]);
});

it('lists marketplace packages filtered by software id', function () {
    Http::fake(['*/api/v1/packages*' => Http::response([
        'data' => [],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 12, 'total' => 0],
    ])]);

    app(ApsConnect::class)->listPackages(['software_id' => 1]);

    Http::assertSent(fn ($request) => $request['software_id'] === 1);
});

it('fetches a single marketplace package by software and package slug', function () {
    Http::fake(['*/api/v1/packages/corptrix/reports-pro' => Http::response([
        'id' => 4,
        'name' => 'Reports Pro',
        'slug' => 'reports-pro',
        'description' => null,
        'icon_url' => null,
        'type' => 'module',
        'is_official' => true,
        'is_featured' => false,
        'is_included_in_base' => false,
        'acquisition_mode' => 'one_time',
        'price_per_day_xof' => null,
        'lifetime_price_xof' => 15000,
        'has_trial_mode' => false,
        'trial_period_days' => null,
        'status' => 'published',
        'downloads_count' => 40,
        'rating_avg' => null,
        'rating_count' => 0,
        'publisher' => null,
        'software' => null,
    ])]);

    $package = app(ApsConnect::class)->getPackage('corptrix', 'reports-pro');

    expect($package->slug)->toBe('reports-pro');
    expect($package->lifetimePriceXof)->toBe(15000);
    expect($package->software)->toBeNull();
});

it('throws AppStationResourceNotFoundException when a package does not exist', function () {
    Http::fake(['*/api/v1/packages/corptrix/missing' => Http::response(['message' => 'Package introuvable.'], 404)]);

    expect(fn () => app(ApsConnect::class)->getPackage('corptrix', 'missing'))
        ->toThrow(AppStationResourceNotFoundException::class, 'Package introuvable.');
});

it('lists a package release history', function () {
    Http::fake(['*/api/v1/packages/corptrix/reports-pro/releases*' => Http::response([
        'data' => [[
            'id' => 9,
            'version' => '1.1.0',
            'platform' => null,
            'channel' => 'stable',
            'release_notes' => null,
            'min_software_version' => '2.0.0',
            'max_software_version' => null,
            'checksum' => 'sha256:def',
            'signature' => null,
            'file_size' => null,
            'is_yanked' => false,
            'published_at' => '2026-08-12T00:00:00+00:00',
        ]],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1],
    ])]);

    $releases = app(ApsConnect::class)->getPackageReleases('corptrix', 'reports-pro');

    expect($releases->items)->toHaveCount(1);
    expect($releases->items[0]->minSoftwareVersion)->toBe('2.0.0');
});

it('lists marketplace categories with nested children', function () {
    Http::fake(['*/api/v1/categories' => Http::response([
        'data' => [[
            'id' => 1,
            'name' => 'Business',
            'slug' => 'business',
            'icon' => 'briefcase',
            'type' => 'software',
            'position' => 1,
            'children' => [
                ['id' => 2, 'name' => 'ERP', 'slug' => 'erp', 'icon' => null, 'type' => 'software', 'position' => 1, 'children' => []],
            ],
        ]],
    ])]);

    $categories = app(ApsConnect::class)->listCategories();

    expect($categories)->toHaveCount(1);
    expect($categories[0]->slug)->toBe('business');
    expect($categories[0]->children)->toHaveCount(1);
    expect($categories[0]->children[0]->slug)->toBe('erp');
});

it('lists marketplace tags', function () {
    Http::fake(['*/api/v1/tags*' => Http::response([
        'data' => [['id' => 5, 'name' => 'Finance', 'slug' => 'finance']],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 1],
    ])]);

    $tags = app(ApsConnect::class)->listTags();

    expect($tags->items)->toHaveCount(1);
    expect($tags->items[0]->slug)->toBe('finance');
});

it('searches the marketplace for softwares and packages', function () {
    Http::fake(['*/api/v1/search*' => Http::response([
        'softwares' => ['data' => [[
            'id' => 1, 'name' => 'Corptrix', 'slug' => 'corptrix', 'tagline' => null, 'description' => null,
            'logo_url' => null, 'banner_url' => null, 'license_type' => null, 'acquisition_mode' => 'subscription',
            'status' => 'published', 'is_featured' => false, 'has_modules' => false, 'price_per_day_xof' => null,
            'lifetime_price_xof' => null, 'downloads_count' => 0, 'rating_avg' => null, 'rating_count' => 0,
        ]]],
        'packages' => ['data' => []],
    ])]);

    $results = app(ApsConnect::class)->search('corp', 'all');

    expect($results->softwares)->toHaveCount(1);
    expect($results->softwares[0]->slug)->toBe('corptrix');
    expect($results->packages)->toBe([]);

    Http::assertSent(fn ($request) => $request['q'] === 'corp' && $request['type'] === 'all');
});
