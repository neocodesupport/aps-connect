<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

use Carbon\CarbonImmutable;

final readonly class SoftwareInstance
{
    public function __construct(
        public int $id,
        public ?string $label,
        public string $licenceKeyMask,
        public ?string $clientReference,
        public string $status,
        public ?CarbonImmutable $lastSeenAt,
        public ?CarbonImmutable $revokedAt,
        public ?CarbonImmutable $registeredAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            label: $data['label'] ?? null,
            licenceKeyMask: (string) $data['licence_key_mask'],
            clientReference: $data['client_reference'] ?? null,
            status: (string) $data['status'],
            lastSeenAt: isset($data['last_seen_at']) ? CarbonImmutable::parse($data['last_seen_at']) : null,
            revokedAt: isset($data['revoked_at']) ? CarbonImmutable::parse($data['revoked_at']) : null,
            registeredAt: isset($data['registered_at']) ? CarbonImmutable::parse($data['registered_at']) : null,
        );
    }
}
