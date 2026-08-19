<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

use Carbon\CarbonImmutable;

final readonly class Device
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $type,
        public ?string $name,
        public ?string $os,
        public ?string $browser,
        public ?CarbonImmutable $lastActiveAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            uuid: (string) $data['uuid'],
            type: (string) $data['type'],
            name: $data['name'] ?? null,
            os: $data['os'] ?? null,
            browser: $data['browser'] ?? null,
            lastActiveAt: isset($data['last_active_at']) ? CarbonImmutable::parse($data['last_active_at']) : null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArrayOrNull(?array $data): ?self
    {
        return $data === null ? null : self::fromArray($data);
    }
}
