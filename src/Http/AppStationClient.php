<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Neocode\ApsConnect\Data\AppStationCredentials;
use Neocode\ApsConnect\Exceptions\AppStationLicenceRejectedException;
use Neocode\ApsConnect\Exceptions\AppStationRequestException;
use Neocode\ApsConnect\Exceptions\AppStationResourceNotFoundException;
use Neocode\ApsConnect\Exceptions\AppStationUnavailableException;
use Neocode\ApsConnect\Exceptions\AppStationValidationException;
use Neocode\ApsConnect\Exceptions\InvalidAppStationApiKeyException;
use Neocode\ApsConnect\Exceptions\PackageReleaseNotFoundException;

final class AppStationClient
{
    /**
     * appstation.baseUrl (from appstation.conf.json / config('aps-connect.appstation_base_url'))
     * is the bare App Station domain — e.g. `https://app-station.neocode.ci`,
     * with no `/api/v1` — exactly like the `aps` CLI's own `AppStationClient`
     * (src/lib/appstationApi.ts) stores it and prefixes every request path with
     * `/api/v1/...` itself. This client does the same here.
     */
    private const string API_PREFIX = 'api/v1/';

    public function __construct(
        private readonly AppStationCredentials $credentials,
        private readonly Factory $http,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function registerInstance(string $licenceKey, string $clientReference, ?string $label = null): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->post(self::API_PREFIX.'integrations/software/instances/register', array_filter([
                'licence_key' => $licenceKey,
                'client_reference' => $clientReference,
                'label' => $label,
            ], static fn (mixed $value): bool => $value !== null)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function downloadPackage(string $instanceApiKey, int $packageReleaseId, ?string $moduleLicenceKey = null): array
    {
        return $this->handle(
            $this->request($instanceApiKey)->post(self::API_PREFIX."integrations/software/packages/{$packageReleaseId}/download", array_filter([
                'module_licence_key' => $moduleLicenceKey,
            ], static fn (mixed $value): bool => $value !== null)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function checkForUpdate(string $instanceApiKey, string $currentVersion, ?string $platform = null, ?string $channel = null): array
    {
        return $this->handle(
            $this->request($instanceApiKey)->post(self::API_PREFIX.'integrations/software/updates/check', array_filter([
                'current_version' => $currentVersion,
                'platform' => $platform,
                'channel' => $channel,
            ], static fn (mixed $value): bool => $value !== null)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function softwareReleases(string $slug, int $page = 1): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX."softwares/{$slug}/releases", ['page' => $page]),
            AppStationResourceNotFoundException::class,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function softwarePackages(string $slug, int $page = 1): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX."softwares/{$slug}/packages", ['page' => $page]),
            AppStationResourceNotFoundException::class,
        );
    }

    /**
     * @param  array{software_id?: int, page?: int}  $filters
     * @return array<string, mixed>
     */
    public function listPackages(array $filters = []): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX.'packages', $filters),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function showPackage(string $softwareSlug, string $slug): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX."packages/{$softwareSlug}/{$slug}"),
            AppStationResourceNotFoundException::class,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function packageReleases(string $softwareSlug, string $slug, int $page = 1): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX."packages/{$softwareSlug}/{$slug}/releases", ['page' => $page]),
            AppStationResourceNotFoundException::class,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function categories(): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX.'categories'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function tags(int $page = 1): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX.'tags', ['page' => $page]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function search(string $query, string $type = 'software'): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX.'search', ['q' => $query, 'type' => $type]),
        );
    }

    private function request(string $apiKey): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->credentials->baseUrl, '/'))
            ->withHeaders(['X-Software-Api-Key' => $apiKey])
            ->acceptJson();
    }

    /**
     * @param  class-string<AppStationRequestException>  $notFoundException
     * @return array<string, mixed>
     */
    private function handle(Response $response, string $notFoundException = PackageReleaseNotFoundException::class): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful()) {
            return $body;
        }

        $message = is_string($body['message'] ?? null) ? $body['message'] : "App Station request failed with status {$response->status()}.";

        throw match ($response->status()) {
            401 => new InvalidAppStationApiKeyException($message, $response->status(), $body),
            403 => new AppStationLicenceRejectedException($message, $response->status(), $body),
            404 => new $notFoundException($message, $response->status(), $body),
            422 => new AppStationValidationException($message, $response->status(), $body, $body['errors'] ?? []),
            429, 503 => new AppStationUnavailableException($message, $response->status(), $body, $this->retryAfter($response)),
            default => new AppStationRequestException($message, $response->status(), $body),
        };
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return $header === '' ? null : (int) $header;
    }
}
