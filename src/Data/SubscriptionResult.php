<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

final readonly class SubscriptionResult
{
    public function __construct(
        public LicenceStatus $licence,
        public ?UsagePeriod $usagePeriod,
        public ?Customer $customer,
        public ?Device $device,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            licence: LicenceStatus::fromArray($data),
            usagePeriod: UsagePeriod::fromArrayOrNull($data['usage_period'] ?? null),
            customer: Customer::fromArrayOrNull($data['customer'] ?? null),
            device: Device::fromArrayOrNull($data['device'] ?? null),
        );
    }
}
