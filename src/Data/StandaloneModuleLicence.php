<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

use Carbon\CarbonImmutable;

final readonly class StandaloneModuleLicence
{
    public function __construct(
        public string $moduleLicenceKey,
        public string $moduleSlug,
        public string $status,
        public bool $active,
        public bool $attached,
        public ?string $motherLicenceKey,
        public ?string $reason,
        public ?CarbonImmutable $expiresAt,
        public ?string $message,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            moduleLicenceKey: (string) $data['module_licence_key'],
            moduleSlug: (string) $data['module_slug'],
            status: (string) $data['status'],
            active: (bool) $data['active'],
            attached: (bool) $data['attached'],
            motherLicenceKey: $data['mother_licence_key'] ?? null,
            reason: $data['reason'] ?? null,
            expiresAt: isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at']) : null,
            message: $data['message'] ?? null,
        );
    }
}
