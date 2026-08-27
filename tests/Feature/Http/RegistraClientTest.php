<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Neocode\ApsConnect\Data\RegistraCredentials;
use Neocode\ApsConnect\Exceptions\InvalidApiKeyException;
use Neocode\ApsConnect\Exceptions\LicenceConflictException;
use Neocode\ApsConnect\Exceptions\LicenceInactiveException;
use Neocode\ApsConnect\Exceptions\LicenceNotFoundException;
use Neocode\ApsConnect\Exceptions\RegistraRequestException;
use Neocode\ApsConnect\Exceptions\RegistraUnavailableException;
use Neocode\ApsConnect\Exceptions\RegistraValidationException;
use Neocode\ApsConnect\Http\RegistraClient;

beforeEach(function () {
    $this->baseUrl = config('aps-connect.registra_base_url');
    $this->credentials = new RegistraCredentials('secret-key', $this->baseUrl, 'production');
    $this->client = new RegistraClient($this->credentials, app(Factory::class));
});

it('sends the X-Software-Api-Key header to the resolved base url', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $this->client->get('software/me');

    Http::assertSent(function ($request) {
        return $request->url() === "{$this->baseUrl}/software/me"
            && $request->hasHeader('X-Software-Api-Key', 'secret-key');
    });
});

it('returns the decoded json body on success', function () {
    Http::fake(['*' => Http::response(['success' => true, 'foo' => 'bar'], 200)]);

    $body = $this->client->post('licences/verify', ['licence_key' => 'LIC-1']);

    expect($body)->toBe(['success' => true, 'foo' => 'bar']);
});

it('sends post payloads as json', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $this->client->post('licences/verify', ['licence_key' => 'LIC-1']);

    Http::assertSent(fn ($request) => $request['licence_key'] === 'LIC-1');
});

it('does not route sandboxable calls to the sandbox prefix in production', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $this->client->post('licences/verify', ['licence_key' => 'LIC-1'], sandboxable: true);

    Http::assertSent(fn ($request) => $request->url() === "{$this->baseUrl}/licences/verify");
});

it('routes sandboxable calls to the sandbox prefix when the environment is development', function () {
    $client = new RegistraClient(
        new RegistraCredentials('dev-key', $this->baseUrl, 'development'),
        app(Factory::class),
    );

    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $client->post('licences/verify', ['licence_key' => 'LIC-1'], sandboxable: true);

    Http::assertSent(fn ($request) => $request->url() === "{$this->baseUrl}/sandbox/licences/verify");
});

it('never routes non sandboxable calls to the sandbox prefix even in development', function () {
    $client = new RegistraClient(
        new RegistraCredentials('dev-key', $this->baseUrl, 'development'),
        app(Factory::class),
    );

    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $client->post('licences/trial', ['customer' => ['email' => 'a@example.com']]);

    Http::assertSent(fn ($request) => $request->url() === "{$this->baseUrl}/licences/trial");
});

it('maps a 401 response to InvalidApiKeyException', function () {
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'Clé invalide.'], 401)]);

    try {
        $this->client->post('licences/verify', ['licence_key' => 'x']);
    } catch (InvalidApiKeyException $e) {
        expect($e->getMessage())->toBe('Clé invalide.');
        expect($e->status)->toBe(401);
        expect($e->body)->toBe(['success' => false, 'message' => 'Clé invalide.']);

        return;
    }

    test()->fail('Expected InvalidApiKeyException was not thrown.');
});

it('maps a 403 response to LicenceInactiveException', function () {
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'Licence bloquée.'], 403)]);

    expect(fn () => $this->client->post('licences/verify', ['licence_key' => 'x']))
        ->toThrow(LicenceInactiveException::class, 'Licence bloquée.');
});

it('maps a 404 response to LicenceNotFoundException', function () {
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'Licence introuvable.'], 404)]);

    expect(fn () => $this->client->post('licences/verify', ['licence_key' => 'x']))
        ->toThrow(LicenceNotFoundException::class, 'Licence introuvable.');
});

it('maps a 409 response to LicenceConflictException', function () {
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'Déjà réclamée.'], 409)]);

    expect(fn () => $this->client->post('subscribe', []))
        ->toThrow(LicenceConflictException::class, 'Déjà réclamée.');
});

it('maps a 422 response to RegistraValidationException carrying validation errors', function () {
    Http::fake(['*' => Http::response([
        'success' => false,
        'message' => 'Validation échouée.',
        'errors' => ['customer.email' => ['Le champ email est requis.']],
    ], 422)]);

    try {
        $this->client->post('licences/trial', []);
    } catch (RegistraValidationException $e) {
        expect($e->status)->toBe(422);
        expect($e->errors)->toBe(['customer.email' => ['Le champ email est requis.']]);

        return;
    }

    test()->fail('Expected RegistraValidationException was not thrown.');
});

it('maps a 429 response to RegistraUnavailableException with the retry-after header', function () {
    Http::fake(['*' => Http::response(
        ['success' => false, 'message' => 'Trop de requêtes.'],
        429,
        ['Retry-After' => '12'],
    )]);

    try {
        $this->client->post('licences/verify', ['licence_key' => 'x']);
    } catch (RegistraUnavailableException $e) {
        expect($e->status)->toBe(429);
        expect($e->retryAfter)->toBe(12);

        return;
    }

    test()->fail('Expected RegistraUnavailableException was not thrown.');
});

it('maps a 503 response to RegistraUnavailableException without a retry-after header', function () {
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'Indisponible.'], 503)]);

    try {
        $this->client->post('licences/verify', ['licence_key' => 'x']);
    } catch (RegistraUnavailableException $e) {
        expect($e->status)->toBe(503);
        expect($e->retryAfter)->toBeNull();

        return;
    }

    test()->fail('Expected RegistraUnavailableException was not thrown.');
});

it('maps any other error status to the generic RegistraRequestException', function () {
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'Erreur serveur.'], 500)]);

    try {
        $this->client->post('licences/verify', ['licence_key' => 'x']);
    } catch (RegistraRequestException $e) {
        expect($e::class)->toBe(RegistraRequestException::class);
        expect($e->status)->toBe(500);

        return;
    }

    test()->fail('Expected RegistraRequestException was not thrown.');
});
