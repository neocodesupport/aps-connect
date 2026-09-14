<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Neocode\ApsConnect\Data\RegistraCredentials;
use Neocode\ApsConnect\Exceptions\InvalidApiKeyException;
use Neocode\ApsConnect\Exceptions\LicenceConflictException;
use Neocode\ApsConnect\Exceptions\LicenceInactiveException;
use Neocode\ApsConnect\Exceptions\LicenceNotFoundException;
use Neocode\ApsConnect\Exceptions\RegistraRequestException;
use Neocode\ApsConnect\Exceptions\RegistraUnavailableException;
use Neocode\ApsConnect\Exceptions\RegistraValidationException;

final class RegistraClient extends ApiClient
{
    public function __construct(
        private readonly RegistraCredentials $credentials,
        Factory $http,
    ) {
        parent::__construct($http);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $uri, array $query = [], bool $sandboxable = false): array
    {
        return $this->handle(
            $this->request()->get($this->resolveUri($uri, $sandboxable), $query),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $uri, array $payload = [], bool $sandboxable = false): array
    {
        return $this->handle(
            $this->request()->post($this->resolveUri($uri, $sandboxable), $payload),
        );
    }

    /**
     * Registra only mirrors a handful of endpoints (licence verify, module
     * verify, subscribe) under sandbox/... for development-environment
     * software — the rest (trial issuance, standalone module licences,
     * software/me) have no sandbox equivalent and are always hit as-is.
     */
    private function resolveUri(string $uri, bool $sandboxable): string
    {
        if ($sandboxable && $this->credentials->environment === 'development') {
            return 'sandbox/'.$uri;
        }

        return $uri;
    }

    private function request(): PendingRequest
    {
        return $this->buildRequest($this->credentials->baseUrl, $this->credentials->apiKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(Response $response): array
    {
        [$body, $message] = $this->decode($response, 'Registra');

        if ($response->successful()) {
            return $body;
        }

        throw match ($response->status()) {
            401 => new InvalidApiKeyException($message, $response->status(), $body),
            403 => new LicenceInactiveException($message, $response->status(), $body),
            404 => new LicenceNotFoundException($message, $response->status(), $body),
            409 => new LicenceConflictException($message, $response->status(), $body),
            422 => new RegistraValidationException($message, $response->status(), $body, $body['errors'] ?? []),
            429, 503 => new RegistraUnavailableException($message, $response->status(), $body, $this->retryAfter($response)),
            default => new RegistraRequestException($message, $response->status(), $body),
        };
    }
}
