<?php

declare(strict_types=1);

namespace Neocode\ApsConnect;

use Neocode\ApsConnect\Data\Category;
use Neocode\ApsConnect\Data\LicenceStatus;
use Neocode\ApsConnect\Data\ModuleTrialIssued;
use Neocode\ApsConnect\Data\ModuleVerification;
use Neocode\ApsConnect\Data\Package;
use Neocode\ApsConnect\Data\PackageDownload;
use Neocode\ApsConnect\Data\PackageRelease;
use Neocode\ApsConnect\Data\Paginated;
use Neocode\ApsConnect\Data\SearchResults;
use Neocode\ApsConnect\Data\Software;
use Neocode\ApsConnect\Data\SoftwareIdentity;
use Neocode\ApsConnect\Data\SoftwareInstanceRegistration;
use Neocode\ApsConnect\Data\SoftwareRelease;
use Neocode\ApsConnect\Data\StandaloneModuleActivation;
use Neocode\ApsConnect\Data\StandaloneModuleAttachment;
use Neocode\ApsConnect\Data\StandaloneModuleLicence;
use Neocode\ApsConnect\Data\SubscriptionResult;
use Neocode\ApsConnect\Data\Tag;
use Neocode\ApsConnect\Data\TrialIssued;
use Neocode\ApsConnect\Data\UpdateCheckResult;
use Neocode\ApsConnect\Http\AppStationClient;
use Neocode\ApsConnect\Http\RegistraClient;

final class ApsConnect
{
    public function __construct(
        private readonly RegistraClient $client,
        private readonly AppStationClient $appStation,
    ) {}

    public function verifyLicence(string $licenceKey): LicenceStatus
    {
        return LicenceStatus::fromArray(
            $this->client->post('licences/verify', ['licence_key' => $licenceKey], sandboxable: true),
        );
    }

    public function verifyModuleLicence(string $licenceKey, string $moduleSlug): ModuleVerification
    {
        return ModuleVerification::fromArray(
            $this->client->post('licences/verify-module', ['licence_key' => $licenceKey, 'module_slug' => $moduleSlug], sandboxable: true),
        );
    }

    /**
     * @param  array{firstname?: string, lastname?: string, email: string, phone?: string, address?: string, software_key?: string}  $customer
     * @param  array{uuid?: string, type?: string, name?: string, os?: string, browser?: string}  $device
     */
    public function subscribe(string $licenceKey, array $customer, array $device = []): SubscriptionResult
    {
        return SubscriptionResult::fromArray(
            $this->client->post('subscribe', array_filter([
                'licence_key' => $licenceKey,
                'customer' => $customer,
                'device' => $device === [] ? null : $device,
            ], static fn (mixed $value): bool => $value !== null), sandboxable: true),
        );
    }

    /**
     * @param  array{firstname?: string, lastname?: string, email: string, phone?: string, address?: string, software_key?: string}  $customer
     */
    public function issueTrial(array $customer): TrialIssued
    {
        return TrialIssued::fromArray(
            $this->client->post('licences/trial', ['customer' => $customer]),
        );
    }

    /**
     * @param  array{firstname?: string, lastname?: string, email: string, phone?: string, address?: string, software_key?: string}  $customer
     */
    public function issueModuleTrial(string $moduleSlug, array $customer): ModuleTrialIssued
    {
        return ModuleTrialIssued::fromArray(
            $this->client->post('modules/trial', ['module_slug' => $moduleSlug, 'customer' => $customer]),
        );
    }

    public function verifyStandaloneModuleLicence(string $moduleLicenceKey): StandaloneModuleLicence
    {
        return StandaloneModuleLicence::fromArray(
            $this->client->post('standalone-module-licences/verify', ['module_licence_key' => $moduleLicenceKey]),
        );
    }

    /**
     * @param  array{firstname?: string, lastname?: string, email: string, phone?: string, address?: string, software_key?: string}  $customer
     */
    public function activateStandaloneModuleLicence(string $moduleLicenceKey, array $customer): StandaloneModuleActivation
    {
        return StandaloneModuleActivation::fromArray(
            $this->client->post('standalone-module-licences/activate', [
                'module_licence_key' => $moduleLicenceKey,
                'customer' => $customer,
            ]),
        );
    }

    public function attachStandaloneModuleLicence(string $moduleLicenceKey, string $motherLicenceKey): StandaloneModuleAttachment
    {
        return StandaloneModuleAttachment::fromArray(
            $this->client->post('standalone-module-licences/attach', [
                'module_licence_key' => $moduleLicenceKey,
                'mother_licence_key' => $motherLicenceKey,
            ]),
        );
    }

    public function lookupByCustomerDevice(string $email, string $deviceUuid): SubscriptionResult
    {
        return SubscriptionResult::fromArray(
            $this->client->post('licences/lookup-by-customer-device', ['email' => $email, 'device_uuid' => $deviceUuid]),
        );
    }

    public function me(): SoftwareIdentity
    {
        $body = $this->client->get('software/me');

        return SoftwareIdentity::fromArray($body['data']);
    }

    /**
     * Registers (or, given the same $clientReference again, resolves) a
     * deployment of this software at a final customer's site with App
     * Station's distribution system, after verifying $licenceKey. Always pass
     * a stable $clientReference (e.g. a tenant or device id) — App Station
     * treats it as the idempotency key: reusing it returns the existing
     * instance and its existing API key, while omitting it creates a new
     * instance (and a new API key) on every call.
     */
    public function registerSoftwareInstance(string $licenceKey, string $clientReference, ?string $label = null): SoftwareInstanceRegistration
    {
        return SoftwareInstanceRegistration::fromArray(
            $this->appStation->registerInstance($licenceKey, $clientReference, $label),
        );
    }

    public function downloadPackage(string $instanceApiKey, int $packageReleaseId, ?string $moduleLicenceKey = null): PackageDownload
    {
        return PackageDownload::fromArray(
            $this->appStation->downloadPackage($instanceApiKey, $packageReleaseId, $moduleLicenceKey),
        );
    }

    public function checkForUpdate(string $instanceApiKey, string $currentVersion, ?string $platform = null, ?string $minStability = null, ?string $currentChannel = null): UpdateCheckResult
    {
        return UpdateCheckResult::fromArray(
            $this->appStation->checkForUpdate($instanceApiKey, $currentVersion, $platform, $minStability, $currentChannel),
        );
    }

    /**
     * @param  array{category_id?: int, featured?: bool, q?: string, sort?: string, page?: int}  $filters
     * @return Paginated<Software>
     */
    public function listSoftwares(array $filters = []): Paginated
    {
        return Paginated::fromArray($this->appStation->softwares($filters), Software::fromArray(...));
    }

    public function getSoftware(string $slug): Software
    {
        return Software::fromArray($this->appStation->showSoftware($slug));
    }

    /**
     * @return Paginated<SoftwareRelease>
     */
    public function getSoftwareReleases(string $slug, int $page = 1): Paginated
    {
        return Paginated::fromArray($this->appStation->softwareReleases($slug, $page), SoftwareRelease::fromArray(...));
    }

    /**
     * @return Paginated<Package>
     */
    public function getSoftwarePackages(string $slug, int $page = 1): Paginated
    {
        return Paginated::fromArray($this->appStation->softwarePackages($slug, $page), Package::fromArray(...));
    }

    /**
     * @param  array{software_id?: int, page?: int}  $filters
     * @return Paginated<Package>
     */
    public function listPackages(array $filters = []): Paginated
    {
        return Paginated::fromArray($this->appStation->listPackages($filters), Package::fromArray(...));
    }

    public function getPackage(string $softwareSlug, string $slug): Package
    {
        return Package::fromArray($this->appStation->showPackage($softwareSlug, $slug));
    }

    /**
     * @return Paginated<PackageRelease>
     */
    public function getPackageReleases(string $softwareSlug, string $slug, int $page = 1): Paginated
    {
        return Paginated::fromArray($this->appStation->packageReleases($softwareSlug, $slug, $page), PackageRelease::fromArray(...));
    }

    /**
     * @return Category[]
     */
    public function listCategories(): array
    {
        return array_map(Category::fromArray(...), $this->appStation->categories()['data'] ?? []);
    }

    /**
     * @return Paginated<Tag>
     */
    public function listTags(int $page = 1): Paginated
    {
        return Paginated::fromArray($this->appStation->tags($page), Tag::fromArray(...));
    }

    public function search(string $query, string $type = 'software'): SearchResults
    {
        return SearchResults::fromArray($this->appStation->search($query, $type));
    }
}
