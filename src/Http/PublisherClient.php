<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Neocode\ApsConnect\Data\PublisherCredentials;
use Neocode\ApsConnect\Exceptions\InvalidPublisherTokenException;
use Neocode\ApsConnect\Exceptions\ProjectAlreadyLinkedException;
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

    private const string DEVELOPER_PREFIX = 'api/v1/developer/';

    private const int MAX_PAGES = 50;

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

    /**
     * Every software on the publisher's account, across all pages.
     *
     * @return list<array<string, mixed>>
     */
    public function listSoftwares(): array
    {
        return $this->fetchAllPages(self::DEVELOPER_PREFIX.'softwares');
    }

    /**
     * @return array<string, mixed>
     */
    public function createSoftware(string $name, ?string $tagline = null, ?string $licenseType = null): array
    {
        return $this->handle($this->request()->post(self::DEVELOPER_PREFIX.'softwares', array_filter(
            ['name' => $name, 'tagline' => $tagline, 'license_type' => $licenseType],
            static fn (?string $value): bool => $value !== null,
        )));
    }

    /**
     * Links this repository (by fingerprint) to the software.
     *
     * @return array{data: array<string, mixed>, registra_dev_sync_error: ?string}
     *
     * @throws ProjectAlreadyLinkedException when it is linked to another repository and $force is false
     */
    public function linkSoftware(int $softwareId, string $projectFingerprint, bool $force): array
    {
        return $this->link("softwares/{$softwareId}/link", $projectFingerprint, $force);
    }

    /**
     * Every module on the publisher's account, across all pages.
     *
     * @return list<array<string, mixed>>
     */
    public function listPackages(): array
    {
        return $this->fetchAllPages(self::DEVELOPER_PREFIX.'packages');
    }

    /**
     * @return array<string, mixed>
     */
    public function createPackage(int $parentSoftwareId, string $name): array
    {
        return $this->handle($this->request()->post(self::DEVELOPER_PREFIX.'packages', [
            'software_id' => $parentSoftwareId,
            'name' => $name,
        ]));
    }

    /**
     * @return array{data: array<string, mixed>, registra_dev_sync_error: ?string}
     *
     * @throws ProjectAlreadyLinkedException when it is linked to another repository and $force is false
     */
    public function linkPackage(int $packageId, string $projectFingerprint, bool $force): array
    {
        return $this->link("packages/{$packageId}/link", $projectFingerprint, $force);
    }

    /**
     * Issues the Registra api key and signing secret for a linked software or
     * module, in the given environment. Returned unwrapped (no `data` key):
     * `software`|`module`+`parentSoftware`, `registra`, `signingSecret`.
     *
     * @return array<string, mixed>
     */
    public function registraInit(string $type, int $id, string $environment, string $projectFingerprint): array
    {
        return $this->handleBody($this->request()->post(self::DEVELOPER_PREFIX.'registra/init', [
            'type' => $type,
            'id' => $id,
            'environment' => $environment,
            'project_fingerprint' => $projectFingerprint,
        ]));
    }

    /**
     * @return array{data: array<string, mixed>, registra_dev_sync_error: ?string}
     */
    private function link(string $uri, string $projectFingerprint, bool $force): array
    {
        $body = $this->handleBody($this->request()->post(self::DEVELOPER_PREFIX.$uri, [
            'project_fingerprint' => $projectFingerprint,
            'force' => $force,
        ]));

        $data = $body['data'] ?? null;
        $syncError = $body['registra_dev_sync_error'] ?? null;

        return [
            'data' => is_array($data) ? $data : [],
            'registra_dev_sync_error' => is_string($syncError) ? $syncError : null,
        ];
    }

    /**
     * Follows App Station's standard Laravel pagination (`meta.current_page`
     * / `meta.last_page`), capped like aps-cli's MAX_PAGES against a server
     * returning inconsistent meta.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAllPages(string $uri): array
    {
        $items = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = $this->handleBody($this->request()->get($uri, ['page' => $page]));

            foreach (is_array($body['data'] ?? null) ? $body['data'] : [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
            $currentPage = is_int($meta['current_page'] ?? null) ? $meta['current_page'] : $page;
            $lastPage = is_int($meta['last_page'] ?? null) ? $meta['last_page'] : $currentPage;

            if ($currentPage >= $lastPage) {
                break;
            }
        }

        return $items;
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
        $data = $this->handleBody($response)['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleBody(Response $response): array
    {
        [$body, $message] = $this->decode($response, 'App Station publisher');

        if ($response->successful()) {
            return $body;
        }

        throw match ($response->status()) {
            401 => new InvalidPublisherTokenException($message, $response->status(), $body),
            409 => new ProjectAlreadyLinkedException($message, $response->status(), $body),
            422 => new PublisherValidationException($message, $response->status(), $body, $body['errors'] ?? []),
            429, 503 => new PublisherUnavailableException($message, $response->status(), $body, $this->retryAfter($response)),
            default => new PublisherRequestException($message, $response->status(), $body),
        };
    }
}
