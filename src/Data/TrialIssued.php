<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

use Carbon\CarbonImmutable;

final readonly class TrialIssued
{
    public function __construct(
        public string $licenceKey,
        public int $trialPeriodDays,
        public ?CarbonImmutable $mustActivateBeforeAt,
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
            licenceKey: (string) $data['licence_key'],
            trialPeriodDays: (int) $data['trial_period_days'],
            mustActivateBeforeAt: isset($data['must_activate_before_at']) ? CarbonImmutable::parse($data['must_activate_before_at']) : null,
            usagePeriod: UsagePeriod::fromArray($data['usage_period']),
            customerEmail: (string) $data['customer']['email'],
            message: $data['message'] ?? null,
        );
    }
}
