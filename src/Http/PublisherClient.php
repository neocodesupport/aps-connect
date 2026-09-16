<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Neocode\ApsConnect\Data\PublisherCredentials;
use Neocode\ApsConnect\Exceptions\InvalidPublisherTokenException;
use Neocode\ApsConnect\Exceptions\PublisherRequestException;
use Neocode\ApsConnect\Exceptions\PublisherUnavailableException;
use Neocode\ApsConnect\Exceptions\PublisherValidationException;

/**
 * Talks to App Station's `/api/v1/publisher/...` endpoints — session-token
 * authenticated (`Authorization: Bearer <token>`, matching `aps-cli`'s own
 * client in src/lib/appstationApi.ts), unlike RegistraClient/AppStationClient
 * which both use the `X-Software-Api-Key` product/instance key. Extends
 * ApiClient for decode()/retryAfter() only — buildRequest() hardcodes the
 * X-Software-Api-Key header, which doesn't apply to this auth scheme.
 */
final class PublisherClient extends ApiClient
{
    private const string API_PREFIX = 'api/v1/publisher/';

    public function __construct(
        private readonly PublisherCredentials $credentials,
        Factory $http,
    ) {
        parent::__construct($http);
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    public function createSoftwareRelease(int $softwareId, array $fields, string $filePath, string $uploadName): array
    {
        return $this->createRelease("softwares/{$softwareId}/releases", $fields, $filePath, $uploadName);
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    public function createPackageRelease(int $packageId, array $fields, string $filePath, string $uploadName): array
    {
        return $this->createRelease("packages/{$packageId}/releases", $fields, $filePath, $uploadName);
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function createRelease(string $uri, array $fields, string $filePath, string $uploadName): array
    {
        $response = $this->request()
            ->attach('release_file', file_get_contents($filePath) ?: '', $uploadName)
            ->post(self::API_PREFIX.$uri, $fields);

        return $this->handle($response);
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->credentials->baseUrl, '/'))
            ->withToken($this->credentials->token)
            ->acceptJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(Response $response): array
    {
        [$body, $message] = $this->decode($response, 'App Station publisher');

        if ($response->successful()) {
            $data = $body['data'] ?? null;

            return is_array($data) ? $data : [];
        }

        throw match ($response->status()) {
            401 => new InvalidPublisherTokenException($message, $response->status(), $body),
            422 => new PublisherValidationException($message, $response->status(), $body, $body['errors'] ?? []),
            429, 503 => new PublisherUnavailableException($message, $response->status(), $body, $this->retryAfter($response)),
            default => new PublisherRequestException($message, $response->status(), $body),
        };
    }
}
