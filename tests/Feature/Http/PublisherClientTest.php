<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Neocode\ApsConnect\Data\PublisherCredentials;
use Neocode\ApsConnect\Exceptions\InvalidPublisherTokenException;
use Neocode\ApsConnect\Exceptions\PublisherRequestException;
use Neocode\ApsConnect\Exceptions\PublisherUnavailableException;
use Neocode\ApsConnect\Exceptions\PublisherValidationException;
use Neocode\ApsConnect\Http\PublisherClient;

beforeEach(function () {
    $this->baseUrl = 'https://app-station.neocode.ci';
    $this->credentials = new PublisherCredentials('session-token', $this->baseUrl);
    $this->client = new PublisherClient($this->credentials, app(Factory::class));

    $this->filePath = sys_get_temp_dir().'/aps-connect-publisher-client-test-'.bin2hex(random_bytes(8)).'.zip';
    file_put_contents($this->filePath, 'fake-zip-contents');
});

afterEach(function () {
    if (is_file($this->filePath)) {
        unlink($this->filePath);
    }
});

it('sends the bearer token and multipart fields when publishing a software release', function () {
    Http::fake(['*' => Http::response(['data' => [
        'id' => 1, 'version' => '1.0.0', 'platform' => null, 'channel' => 'stable',
        'release_notes' => null, 'checksum' => 'sha256:x', 'signature' => null,
        'file_size' => 18, 'is_yanked' => false, 'published_at' => null,
    ]], 201)]);

    $data = $this->client->createSoftwareRelease(
        7,
        ['version' => '1.0.0', 'channel' => 'stable', 'platform' => 'universal'],
        $this->filePath,
        'app-1.0.0.zip',
    );

    expect($data['id'])->toBe(1);

    Http::assertSent(function ($request) {
        return $request->url() === "{$this->baseUrl}/api/v1/publisher/softwares/7/releases"
            && $request->hasHeader('Authorization', 'Bearer session-token')
            && str_contains($request->body(), '1.0.0');
    });
});

it('targets the package endpoint when publishing a module release', function () {
    Http::fake(['*' => Http::response(['data' => [
        'id' => 2, 'version' => '1.0.0', 'platform' => null, 'channel' => 'stable',
        'release_notes' => null, 'min_software_version' => null, 'max_software_version' => null,
        'checksum' => 'sha256:x', 'signature' => null, 'file_size' => 18,
        'is_yanked' => false, 'published_at' => null,
    ]], 201)]);

    $this->client->createPackageRelease(
        9,
        ['version' => '1.0.0', 'channel' => 'stable', 'platform' => 'universal'],
        $this->filePath,
        'module-1.0.0.zip',
    );

    Http::assertSent(fn ($request) => $request->url() === "{$this->baseUrl}/api/v1/publisher/packages/9/releases");
});

it('maps a 401 response to InvalidPublisherTokenException', function () {
    Http::fake(['*' => Http::response(['message' => 'Session invalide.'], 401)]);

    expect(fn () => $this->client->createSoftwareRelease(7, ['version' => '1.0.0'], $this->filePath, 'x.zip'))
        ->toThrow(InvalidPublisherTokenException::class, 'Session invalide.');
});

it('maps a 422 response to PublisherValidationException carrying validation errors', function () {
    Http::fake(['*' => Http::response([
        'message' => 'Validation échouée.',
        'errors' => ['version' => ['Format invalide.']],
    ], 422)]);

    try {
        $this->client->createSoftwareRelease(7, ['version' => 'bad'], $this->filePath, 'x.zip');
    } catch (PublisherValidationException $e) {
        expect($e->status)->toBe(422);
        expect($e->errors)->toBe(['version' => ['Format invalide.']]);

        return;
    }

    test()->fail('Expected PublisherValidationException was not thrown.');
});

it('maps a 429 response to PublisherUnavailableException with the retry-after header', function () {
    Http::fake(['*' => Http::response(['message' => 'Trop de requêtes.'], 429, ['Retry-After' => '5'])]);

    try {
        $this->client->createSoftwareRelease(7, ['version' => '1.0.0'], $this->filePath, 'x.zip');
    } catch (PublisherUnavailableException $e) {
        expect($e->status)->toBe(429);
        expect($e->retryAfter)->toBe(5);

        return;
    }

    test()->fail('Expected PublisherUnavailableException was not thrown.');
});

it('maps any other error status to the generic PublisherRequestException', function () {
    Http::fake(['*' => Http::response(['message' => 'Erreur serveur.'], 500)]);

    try {
        $this->client->createSoftwareRelease(7, ['version' => '1.0.0'], $this->filePath, 'x.zip');
    } catch (PublisherRequestException $e) {
        expect($e::class)->toBe(PublisherRequestException::class);
        expect($e->status)->toBe(500);

        return;
    }

    test()->fail('Expected PublisherRequestException was not thrown.');
});
