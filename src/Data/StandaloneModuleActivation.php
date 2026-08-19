<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

use Carbon\CarbonImmutable;

final readonly class StandaloneModuleActivation
{
    public function __construct(
        public string $moduleLicenceKey,
        public string $status,
        public ?CarbonImmutable $expiresAt,
        public ?UsagePeriod $usagePeriod,
        public ?Customer $customer,
        public bool $already,
        public ?string $message,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            moduleLicenceKey: (string) $data['module_licence_key'],
            status: (string) $data['status'],
            expiresAt: isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at']) : null,
            usagePeriod: UsagePeriod::fromArrayOrNull($data['usage_period'] ?? null),
            customer: Customer::fromArrayOrNull($data['customer'] ?? null),
            already: (bool) ($data['already'] ?? false),
            message: $data['message'] ?? null,
        );
    }
}
