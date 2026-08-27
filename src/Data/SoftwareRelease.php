<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

use Carbon\CarbonImmutable;

final readonly class SoftwareRelease
{
    public function __construct(
        public int $id,
        public string $version,
        public ?string $platform,
        public ?string $channel,
        public ?string $releaseNotes,
        public string $checksum,
        public ?string $signature,
        public ?int $fileSize,
        public bool $isYanked,
        public ?CarbonImmutable $publishedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            version: (string) $data['version'],
            platform: $data['platform'] ?? null,
            channel: $data['channel'] ?? null,
            releaseNotes: $data['release_notes'] ?? null,
            checksum: (string) $data['checksum'],
            signature: $data['signature'] ?? null,
            fileSize: isset($data['file_size']) ? (int) $data['file_size'] : null,
            isYanked: (bool) ($data['is_yanked'] ?? false),
            publishedAt: isset($data['published_at']) ? CarbonImmutable::parse($data['published_at']) : null,
        );
    }
}
