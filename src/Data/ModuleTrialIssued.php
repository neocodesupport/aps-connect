<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

final readonly class ModuleTrialIssued
{
    public function __construct(
        public string $moduleLicenceKey,
        public string $moduleSlug,
        public int $trialPeriodDays,
        public UsagePeriod $usagePeriod,
        public string $customerEmail,
        public ?string $message,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            moduleLicenceKey: (string) $data['module_licence_key'],
            moduleSlug: (string) $data['module_slug'],
            trialPeriodDays: (int) $data['trial_period_days'],
            usagePeriod: UsagePeriod::fromArray($data['usage_period']),
            customerEmail: (string) $data['customer']['email'],
            message: $data['message'] ?? null,
        );
    }
}
