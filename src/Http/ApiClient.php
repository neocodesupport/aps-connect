<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Shared request/response plumbing for the Registra and App Station HTTP
 * clients: both authenticate with the same `X-Software-Api-Key` header
 * against a per-product base URL, decode the same JSON envelope shape, and
 * parse `Retry-After` the same way. Each client keeps its own status code to
 * exception mapping, since the two products don't throw the same exceptions.
 */
abstract class ApiClient
{
    public function __construct(protected readonly Factory $http) {}

    protected function buildRequest(string $baseUrl, string $apiKey): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($baseUrl, '/'))
            ->withHeaders(['X-Software-Api-Key' => $apiKey])
            ->acceptJson();
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function decode(Response $response, string $product): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $message = is_string($body['message'] ?? null) ? $body['message'] : "{$product} request failed with status {$response->status()}.";

        return [$body, $message];
    }

    /**
     * Retry-After (RFC 7231 §7.1.3) is either a number of seconds or an
     * HTTP-date; a plain `(int)` cast on the latter would silently produce 0
     * (retry immediately) instead of the intended wait.
     */
    protected function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if ($header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }
}
