<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Http;

use ApsConnect\ApsConnect\Data\RegistraCredentials;
use ApsConnect\ApsConnect\Exceptions\InvalidApiKeyException;
use ApsConnect\ApsConnect\Exceptions\LicenceConflictException;
use ApsConnect\ApsConnect\Exceptions\LicenceInactiveException;
use ApsConnect\ApsConnect\Exceptions\LicenceNotFoundException;
use ApsConnect\ApsConnect\Exceptions\RegistraRequestException;
use ApsConnect\ApsConnect\Exceptions\RegistraUnavailableException;
use ApsConnect\ApsConnect\Exceptions\RegistraValidationException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class RegistraClient
{
    public function __construct(
        private readonly RegistraCredentials $credentials,
        private readonly Factory $http,
    ) {}

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
        return $this->http
            ->baseUrl(rtrim($this->credentials->baseUrl, '/'))
            ->withHeaders(['X-Software-Api-Key' => $this->credentials->apiKey])
            ->acceptJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(Response $response): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful()) {
            return $body;
        }

        $message = is_string($body['message'] ?? null) ? $body['message'] : "Registra request failed with status {$response->status()}.";

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

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return $header === '' ? null : (int) $header;
    }
}
