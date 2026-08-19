<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

final readonly class UsagePeriod
{
    public function __construct(
        public int $value,
        public string $unit,
        public string $label,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            value: (int) $data['value'],
            unit: (string) $data['unit'],
            label: (string) $data['label'],
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
