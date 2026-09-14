<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Neocode\ApsConnect\Data\AppStationCredentials;
use Neocode\ApsConnect\Exceptions\AppStationLicenceRejectedException;
use Neocode\ApsConnect\Exceptions\AppStationRequestException;
use Neocode\ApsConnect\Exceptions\AppStationResourceNotFoundException;
use Neocode\ApsConnect\Exceptions\AppStationUnavailableException;
use Neocode\ApsConnect\Exceptions\AppStationValidationException;
use Neocode\ApsConnect\Exceptions\InvalidAppStationApiKeyException;
use Neocode\ApsConnect\Exceptions\PackageReleaseNotFoundException;
use Neocode\ApsConnect\Http\AppStationClient;

beforeEach(function () {
    // appstation.baseUrl is the bare App Station domain (no /api/v1) — the
    // `aps` CLI's own AppStationClient stores it the same way and prefixes
    // every request path with /api/v1/... itself; AppStationClient mirrors
    // that here (see AppStationClient::API_PREFIX).
    $this->baseUrl = 'https://app-station.neocode.ci';
    $this->credentials = new AppStationCredentials('product-key', $this->baseUrl);
    $this->client = new AppStationClient($this->credentials, app(Factory::class));
});

it('sends the product api key when registering an instance', function () {
    Http::fake(['*' => Http::response(['instance' => [], 'api_key' => 'x'], 201)]);

    $this->client->registerInstance('LIC-1', 'tenant-1');

    Http::assertSent(function ($request) {
        return $request->url() === "{$this->baseUrl}/api/v1/integrations/software/instances/register"
            && $request->hasHeader('X-Software-Api-Key', 'product-key')
            && $request['licence_key'] === 'LIC-1'
            && $request['client_reference'] === 'tenant-1';
    });
});

it('omits the label when registering an instance without one', function () {
    Http::fake(['*' => Http::response(['instance' => [], 'api_key' => 'x'], 201)]);

    $this->client->registerInstance('LIC-1', 'tenant-1');

    Http::assertSent(fn ($request) => ! array_key_exists('label', $request->data()));
});

it('sends the instance api key when downloading a package release', function () {
    Http::fake(['*' => Http::response(['url' => 'https://cdn.test/x', 'expires_at' => '2026-08-15T00:00:00+00:00', 'checksum' => 'sha256:abc'], 200)]);

    $this->client->downloadPackage('instance-key', 42, 'MOD-1');

    Http::assertSent(function ($request) {
        return $request->url() === "{$this->baseUrl}/api/v1/integrations/software/packages/42/download"
            && $request->hasHeader('X-Software-Api-Key', 'instance-key')
            && $request['module_licence_key'] === 'MOD-1';
    });
});

it('omits the module licence key when downloading without one', function () {
    Http::fake(['*' => Http::response(['url' => 'https://cdn.test/x', 'expires_at' => '2026-08-15T00:00:00+00:00', 'checksum' => 'sha256:abc'], 200)]);

    $this->client->downloadPackage('instance-key', 42);

    Http::assertSent(fn ($request) => ! array_key_exists('module_licence_key', $request->data()));
});

it('sends the instance api key when checking for an update', function () {
    Http::fake(['*' => Http::response(['update_available' => false, 'latest_version' => null], 200)]);

    $this->client->checkForUpdate('instance-key', '1.2.0', 'windows', 'beta', 'stable');

    Http::assertSent(function ($request) {
        return $request->url() === "{$this->baseUrl}/api/v1/integrations/software/updates/check"
            && $request->hasHeader('X-Software-Api-Key', 'instance-key')
            && $request['current_version'] === '1.2.0'
            && $request['platform'] === 'windows'
            && $request['min_stability'] === 'beta'
            && $request['current_channel'] === 'stable';
    });
});

it('omits platform, min_stability and current_channel when checking for an update without them', function () {
    Http::fake(['*' => Http::response(['update_available' => false, 'latest_version' => null], 200)]);

    $this->client->checkForUpdate('instance-key', '1.2.0');

    Http::assertSent(fn ($request) => ! array_key_exists('platform', $request->data())
        && ! array_key_exists('min_stability', $request->data())
        && ! array_key_exists('current_channel', $request->data()));
});

it('maps a 401 response to InvalidAppStationApiKeyException', function () {
    Http::fake(['*' => Http::response(['message' => 'Clé API instance invalide.'], 401)]);

    try {
        $this->client->downloadPackage('bad-key', 1);
    } catch (InvalidAppStationApiKeyException $e) {
        expect($e->getMessage())->toBe('Clé API instance invalide.');
        expect($e->status)->toBe(401);

        return;
    }

    test()->fail('Expected InvalidAppStationApiKeyException was not thrown.');
});

it('maps a 403 response to AppStationLicenceRejectedException', function () {
    Http::fake(['*' => Http::response(['message' => 'Licence invalide.'], 403)]);

    expect(fn () => $this->client->registerInstance('LIC-1', 'tenant-1'))
        ->toThrow(AppStationLicenceRejectedException::class, 'Licence invalide.');
});

it('maps a 404 response to PackageReleaseNotFoundException', function () {
    Http::fake(['*' => Http::response(['message' => 'Release introuvable.'], 404)]);

    expect(fn () => $this->client->downloadPackage('instance-key', 999))
        ->toThrow(PackageReleaseNotFoundException::class, 'Release introuvable.');
});

it('maps a 422 response to AppStationValidationException carrying validation errors', function () {
    Http::fake(['*' => Http::response([
        'message' => 'Validation échouée.',
        'errors' => ['module_licence_key' => ['Licence module requise pour ce package.']],
    ], 422)]);

    try {
        $this->client->downloadPackage('instance-key', 1);
    } catch (AppStationValidationException $e) {
        expect($e->status)->toBe(422);
        expect($e->errors)->toBe(['module_licence_key' => ['Licence module requise pour ce package.']]);

        return;
    }

    test()->fail('Expected AppStationValidationException was not thrown.');
});

it('maps a 429 response to AppStationUnavailableException with the retry-after header', function () {
    Http::fake(['*' => Http::response(['message' => 'Trop de requêtes.'], 429, ['Retry-After' => '5'])]);

    try {
        $this->client->checkForUpdate('instance-key', '1.0.0');
    } catch (AppStationUnavailableException $e) {
        expect($e->status)->toBe(429);
        expect($e->retryAfter)->toBe(5);

        return;
    }

    test()->fail('Expected AppStationUnavailableException was not thrown.');
});

it('parses an HTTP-date Retry-After header into a number of seconds', function () {
    Http::fake(['*' => Http::response(
        ['message' => 'Trop de requêtes.'],
        429,
        ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 30)],
    )]);

    try {
        $this->client->checkForUpdate('instance-key', '1.0.0');
    } catch (AppStationUnavailableException $e) {
        expect($e->retryAfter)->toBeGreaterThanOrEqual(28)->toBeLessThanOrEqual(30);

        return;
    }

    test()->fail('Expected AppStationUnavailableException was not thrown.');
});

it('maps a 503 response to AppStationUnavailableException without a retry-after header', function () {
    Http::fake(['*' => Http::response(['message' => 'Indisponible.'], 503)]);

    try {
        $this->client->registerInstance('LIC-1', 'tenant-1');
    } catch (AppStationUnavailableException $e) {
        expect($e->status)->toBe(503);
        expect($e->retryAfter)->toBeNull();

        return;
    }

    test()->fail('Expected AppStationUnavailableException was not thrown.');
});

it('sends the product api key when listing marketplace packages', function () {
    Http::fake(['*' => Http::response(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 12, 'total' => 0]], 200)]);

    $this->client->listPackages(['software_id' => 1]);

    Http::assertSent(function ($request) {
        return $request->url() === "{$this->baseUrl}/api/v1/packages?software_id=1"
            && $request->hasHeader('X-Software-Api-Key', 'product-key');
    });
});

it('maps a 404 response from a package lookup to AppStationResourceNotFoundException, not PackageReleaseNotFoundException', function () {
    Http::fake(['*' => Http::response(['message' => 'Package introuvable.'], 404)]);

    try {
        $this->client->showPackage('corptrix', 'missing');
    } catch (AppStationResourceNotFoundException $e) {
        expect($e->getMessage())->toBe('Package introuvable.');
        expect($e)->not->toBeInstanceOf(PackageReleaseNotFoundException::class);

        return;
    }

    test()->fail('Expected AppStationResourceNotFoundException was not thrown.');
});

it('still maps a 404 from downloadPackage to PackageReleaseNotFoundException, unaffected by the catalogue not-found override', function () {
    Http::fake(['*' => Http::response(['message' => 'Release introuvable.'], 404)]);

    expect(fn () => $this->client->downloadPackage('instance-key', 1))
        ->toThrow(PackageReleaseNotFoundException::class, 'Release introuvable.');
});

it('sends the product api key when listing marketplace softwares', function () {
    Http::fake(['*' => Http::response(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 12, 'total' => 0]], 200)]);

    $this->client->softwares(['category_id' => 2]);

    Http::assertSent(function ($request) {
        return $request->url() === "{$this->baseUrl}/api/v1/softwares?category_id=2"
            && $request->hasHeader('X-Software-Api-Key', 'product-key');
    });
});

it('fetches a single marketplace software by slug', function () {
    Http::fake(['*' => Http::response(['id' => 1, 'name' => 'Corptrix', 'slug' => 'corptrix'], 200)]);

    $this->client->showSoftware('corptrix');

    Http::assertSent(fn ($request) => $request->url() === "{$this->baseUrl}/api/v1/softwares/corptrix");
});

it('requests categories without pagination params', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->client->categories();

    Http::assertSent(fn ($request) => $request->url() === "{$this->baseUrl}/api/v1/categories");
});

it('maps any other error status to the generic AppStationRequestException', function () {
    Http::fake(['*' => Http::response(['message' => 'Erreur serveur.'], 500)]);

    try {
        $this->client->checkForUpdate('instance-key', '1.0.0');
    } catch (AppStationRequestException $e) {
        expect($e::class)->toBe(AppStationRequestException::class);
        expect($e->status)->toBe(500);

        return;
    }

    test()->fail('Expected AppStationRequestException was not thrown.');
});
