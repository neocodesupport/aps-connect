<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

final readonly class Software
{
    /**
     * @param  Category[]  $categories
     * @param  Tag[]  $tags
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $tagline,
        public ?string $description,
        public ?string $logoUrl,
        public ?string $bannerUrl,
        public ?string $licenseType,
        public string $acquisitionMode,
        public ?string $status,
        public bool $isFeatured,
        public bool $hasModules,
        public ?int $pricePerDayXof,
        public ?int $lifetimePriceXof,
        public int $downloadsCount,
        public ?float $ratingAvg,
        public int $ratingCount,
        public ?Publisher $publisher,
        public array $categories,
        public array $tags,
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
            tagline: $data['tagline'] ?? null,
            description: $data['description'] ?? null,
            logoUrl: $data['logo_url'] ?? null,
            bannerUrl: $data['banner_url'] ?? null,
            licenseType: $data['license_type'] ?? null,
            acquisitionMode: (string) $data['acquisition_mode'],
            status: $data['status'] ?? null,
            isFeatured: (bool) ($data['is_featured'] ?? false),
            hasModules: (bool) ($data['has_modules'] ?? false),
            pricePerDayXof: isset($data['price_per_day_xof']) ? (int) $data['price_per_day_xof'] : null,
            lifetimePriceXof: isset($data['lifetime_price_xof']) ? (int) $data['lifetime_price_xof'] : null,
            downloadsCount: (int) ($data['downloads_count'] ?? 0),
            ratingAvg: isset($data['rating_avg']) ? (float) $data['rating_avg'] : null,
            ratingCount: (int) ($data['rating_count'] ?? 0),
            publisher: isset($data['publisher']) && is_array($data['publisher']) ? Publisher::fromArray($data['publisher']) : null,
            categories: array_map(Category::fromArray(...), $data['categories'] ?? []),
            tags: array_map(Tag::fromArray(...), $data['tags'] ?? []),
        );
    }
}
