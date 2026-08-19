<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect;

use ApsConnect\ApsConnect\Data\LicenceStatus;
use ApsConnect\ApsConnect\Data\ModuleTrialIssued;
use ApsConnect\ApsConnect\Data\ModuleVerification;
use ApsConnect\ApsConnect\Data\SoftwareIdentity;
use ApsConnect\ApsConnect\Data\StandaloneModuleActivation;
use ApsConnect\ApsConnect\Data\StandaloneModuleAttachment;
use ApsConnect\ApsConnect\Data\StandaloneModuleLicence;
use ApsConnect\ApsConnect\Data\SubscriptionResult;
use ApsConnect\ApsConnect\Data\TrialIssued;
use ApsConnect\ApsConnect\Http\RegistraClient;

final class ApsConnect
{
    public function __construct(private readonly RegistraClient $client) {}

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
}
