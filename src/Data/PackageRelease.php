<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

use Carbon\CarbonImmutable;
use Neocode\ApsConnect\Data\Concerns\ParsesReleaseFields;

final readonly class PackageRelease
{
    use ParsesReleaseFields;

    public function __construct(
        public int $id,
        public string $version,
        public ?string $platform,
        public ?string $channel,
        public ?string $releaseNotes,
        public ?string $minSoftwareVersion,
        public ?string $maxSoftwareVersion,
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
            ...self::parseReleaseFields($data),
            minSoftwareVersion: $data['min_software_version'] ?? null,
            maxSoftwareVersion: $data['max_software_version'] ?? null,
        );
    }
}
