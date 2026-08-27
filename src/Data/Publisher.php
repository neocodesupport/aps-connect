<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

final readonly class Publisher
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public ?string $logoUrl,
        public ?string $website,
        public bool $isVerified,
        public ?string $status,
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
            logoUrl: $data['logo_url'] ?? null,
            website: $data['website'] ?? null,
            isVerified: (bool) ($data['is_verified'] ?? false),
            status: $data['status'] ?? null,
        );
    }
}
