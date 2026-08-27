<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

use Carbon\CarbonImmutable;

final readonly class UpdateCheckResult
{
    public function __construct(
        public bool $updateAvailable,
        public ?string $latestVersion,
        public ?SoftwareRelease $release,
        public ?string $url,
        public ?CarbonImmutable $expiresAt,
        public ?string $checksum,
        public ?string $signature,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            updateAvailable: (bool) $data['update_available'],
            latestVersion: $data['latest_version'] ?? null,
            release: isset($data['release']) ? SoftwareRelease::fromArray($data['release']) : null,
            url: $data['url'] ?? null,
            expiresAt: isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at']) : null,
            checksum: $data['checksum'] ?? null,
            signature: $data['signature'] ?? null,
        );
    }
}
