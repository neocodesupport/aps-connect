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

final class AppStationClient extends ApiClient
{
    /**
     * appstation.baseUrl (from appstation.conf.json) is the bare App Station
     * domain — e.g. `https://app-station.neocode.ci`,
     * with no `/api/v1` — exactly like the `aps` CLI's own `AppStationClient`
     * (src/lib/appstationApi.ts) stores it and prefixes every request path with
     * `/api/v1/...` itself. This client does the same here.
     */
    private const string API_PREFIX = 'api/v1/';

    public function __construct(
        private readonly AppStationCredentials $credentials,
        Factory $http,
    ) {
        parent::__construct($http);
    }

    /**
     * @return array<string, mixed>
     */
    public function registerInstance(string $licenceKey, string $clientReference, ?string $label = null): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->post(self::API_PREFIX.'integrations/software/instances/register', $this->withoutNulls([
                'licence_key' => $licenceKey,
                'client_reference' => $clientReference,
                'label' => $label,
            ])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function downloadPackage(string $instanceApiKey, int $packageReleaseId, ?string $moduleLicenceKey = null): array
    {
        return $this->handle(
            $this->request($instanceApiKey)->post(self::API_PREFIX."integrations/software/packages/{$packageReleaseId}/download", $this->withoutNulls([
                'module_licence_key' => $moduleLicenceKey,
            ])),
            PackageReleaseNotFoundException::class,
        );
    }

    /**
     * @param  string|null  $minStability  Least stable release channel to consider (nightly, alpha, beta, rc, stable — default stable). A release published on a less stable channel than this is never offered, even at a newer version number.
     * @param  string|null  $currentChannel  Channel the caller is currently on (default stable). Only lets App Station offer a same-version upgrade to a *more* stable channel (e.g. 1.0.0-rc -> 1.0.0-stable), never the reverse.
     * @return array<string, mixed>
     */
    public function checkForUpdate(string $instanceApiKey, string $currentVersion, ?string $platform = null, ?string $minStability = null, ?string $currentChannel = null): array
    {
        return $this->handle(
            $this->request($instanceApiKey)->post(self::API_PREFIX.'integrations/software/updates/check', $this->withoutNulls([
                'current_version' => $currentVersion,
                'platform' => $platform,
                'min_stability' => $minStability,
                'current_channel' => $currentChannel,
            ])),
        );
    }

    /**
     * @param  array{category_id?: int, featured?: bool, q?: string, sort?: string, page?: int}  $filters
     * @return array<string, mixed>
     */
    public function softwares(array $filters = []): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX.'softwares', $filters),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function showSoftware(string $slug): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX."softwares/{$slug}"),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function softwareReleases(string $slug, int $page = 1): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX."softwares/{$slug}/releases", ['page' => $page]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function softwarePackages(string $slug, int $page = 1): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX."softwares/{$slug}/packages", ['page' => $page]),
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
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function packageReleases(string $softwareSlug, string $slug, int $page = 1): array
    {
        return $this->handle(
            $this->request($this->credentials->apiKey)->get(self::API_PREFIX."packages/{$softwareSlug}/{$slug}/releases", ['page' => $page]),
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
        return $this->buildRequest($this->credentials->baseUrl, $apiKey);
    }

    /**
     * @param  class-string<AppStationRequestException>  $notFoundException
     * @return array<string, mixed>
     */
    private function handle(Response $response, string $notFoundException = AppStationResourceNotFoundException::class): array
    {
        [$body, $message] = $this->decode($response, 'App Station');

        if ($response->successful()) {
            return $body;
        }

        throw match ($response->status()) {
            401 => new InvalidAppStationApiKeyException($message, $response->status(), $body),
            403 => new AppStationLicenceRejectedException($message, $response->status(), $body),
            404 => new $notFoundException($message, $response->status(), $body),
            422 => new AppStationValidationException($message, $response->status(), $body, $body['errors'] ?? []),
            429, 503 => new AppStationUnavailableException($message, $response->status(), $body, $this->retryAfter($response)),
            default => new AppStationRequestException($message, $response->status(), $body),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutNulls(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }
}
