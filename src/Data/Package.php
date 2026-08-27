<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

final readonly class Package
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public ?string $iconUrl,
        public ?string $type,
        public bool $isOfficial,
        public bool $isFeatured,
        public bool $isIncludedInBase,
        public string $acquisitionMode,
        public ?int $pricePerDayXof,
        public ?int $lifetimePriceXof,
        public bool $hasTrialMode,
        public ?int $trialPeriodDays,
        public ?string $status,
        public int $downloadsCount,
        public ?float $ratingAvg,
        public int $ratingCount,
        public ?Software $software,
        public ?Publisher $publisher,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
            slug: (string) $data['slug'],
            description: $data['description'] ?? null,
            iconUrl: $data['icon_url'] ?? null,
            type: $data['type'] ?? null,
            isOfficial: (bool) ($data['is_official'] ?? false),
            isFeatured: (bool) ($data['is_featured'] ?? false),
            isIncludedInBase: (bool) ($data['is_included_in_base'] ?? false),
            acquisitionMode: (string) $data['acquisition_mode'],
            pricePerDayXof: isset($data['price_per_day_xof']) ? (int) $data['price_per_day_xof'] : null,
            lifetimePriceXof: isset($data['lifetime_price_xof']) ? (int) $data['lifetime_price_xof'] : null,
            hasTrialMode: (bool) ($data['has_trial_mode'] ?? false),
            trialPeriodDays: isset($data['trial_period_days']) ? (int) $data['trial_period_days'] : null,
            status: $data['status'] ?? null,
            downloadsCount: (int) ($data['downloads_count'] ?? 0),
            ratingAvg: isset($data['rating_avg']) ? (float) $data['rating_avg'] : null,
            ratingCount: (int) ($data['rating_count'] ?? 0),
            software: isset($data['software']) && is_array($data['software']) ? Software::fromArray($data['software']) : null,
            publisher: isset($data['publisher']) && is_array($data['publisher']) ? Publisher::fromArray($data['publisher']) : null,
        );
    }
}
