<?php

declare(strict_types=1);

use ApsConnect\ApsConnect\Data\AppStationCredentials;
use ApsConnect\ApsConnect\Exceptions\AppStationLicenceRejectedException;
use ApsConnect\ApsConnect\Exceptions\AppStationRequestException;
use ApsConnect\ApsConnect\Exceptions\AppStationUnavailableException;
use ApsConnect\ApsConnect\Exceptions\AppStationValidationException;
use ApsConnect\ApsConnect\Exceptions\InvalidAppStationApiKeyException;
use ApsConnect\ApsConnect\Exceptions\PackageReleaseNotFoundException;
use ApsConnect\ApsConnect\Http\AppStationClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // appstation.baseUrl is the bare App Station domain (no /api/v1) — the
    // `aps` CLI's own AppStationClient stores it the same way and prefixes
    // every request path with /api/v1/... itself; AppStationClient mirrors
    // that here (see AppStationClient::API_PREFIX).
    $this->baseUrl = config('aps-connect.appstation_base_url');
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

    $this->client->checkForUpdate('instance-key', '1.2.0', 'windows', 'stable');

    Http::assertSent(function ($request) {
        return $request->url() === "{$this->baseUrl}/api/v1/integrations/software/updates/check"
            && $request->hasHeader('X-Software-Api-Key', 'instance-key')
            && $request['current_version'] === '1.2.0'
            && $request['platform'] === 'windows'
            && $request['channel'] === 'stable';
    });
});

it('omits platform and channel when checking for an update without them', function () {
    Http::fake(['*' => Http::response(['update_available' => false, 'latest_version' => null], 200)]);

    $this->client->checkForUpdate('instance-key', '1.2.0');

    Http::assertSent(fn ($request) => ! array_key_exists('platform', $request->data()) && ! array_key_exists('channel', $request->data()));
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
