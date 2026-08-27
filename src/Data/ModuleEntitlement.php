<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

use Carbon\CarbonImmutable;

final readonly class ModuleEntitlement
{
    public function __construct(
        public string $slug,
        public string $status,
        public bool $active,
        public ?CarbonImmutable $expiresAt,
        public ?int $remainingDays,
        public ?string $reason,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            slug: (string) $data['slug'],
            status: (string) $data['status'],
            active: (bool) $data['active'],
            expiresAt: isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at']) : null,
            remainingDays: isset($data['remaining_days']) ? (int) $data['remaining_days'] : null,
            reason: $data['reason'] ?? null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @return list<self>
     */
    public static function collectionFromArray(array $data): array
    {
        return array_map(self::fromArray(...), $data);
    }
}
