<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

use Carbon\CarbonImmutable;

final readonly class PackageDownload
{
    public function __construct(
        public string $url,
        public CarbonImmutable $expiresAt,
        public string $checksum,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            url: (string) $data['url'],
            expiresAt: CarbonImmutable::parse($data['expires_at']),
            checksum: (string) $data['checksum'],
        );
    }
}
