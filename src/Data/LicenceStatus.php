<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

use Carbon\CarbonImmutable;

final readonly class LicenceStatus
{
    /**
     * @param  list<ModuleEntitlement>  $modules
     */
    public function __construct(
        public string $licenceKey,
        public bool $active,
        public bool $statusActive,
        public bool $notExpired,
        public ?string $reason,
        public string $status,
        public ?CarbonImmutable $expiresAt,
        public ?int $remainingDays,
        public array $modules,
        public ?string $message,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            licenceKey: (string) $data['licence_key'],
            active: (bool) $data['active'],
            statusActive: (bool) $data['status_active'],
            notExpired: (bool) $data['not_expired'],
            reason: $data['reason'] ?? null,
            status: (string) $data['status'],
            expiresAt: isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at']) : null,
            remainingDays: isset($data['remaining_days']) ? (int) $data['remaining_days'] : null,
            modules: ModuleEntitlement::collectionFromArray($data['modules'] ?? []),
            message: $data['message'] ?? null,
        );
    }
}
