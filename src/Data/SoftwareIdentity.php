<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

use Carbon\CarbonImmutable;

final readonly class SoftwareIdentity
{
    public function __construct(
        public string $token,
        public string $key,
        public string $name,
        public string $slug,
        public string $environment,
        public bool $hasModules,
        public bool $hasApiKey,
        public ?string $apiKeyFingerprint,
        public ?string $linkedProductionToken,
        public bool $hasPreviousApiKeyGracePeriod,
        public ?CarbonImmutable $apiKeyPreviousExpiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            token: (string) $data['token'],
            key: (string) $data['key'],
            name: (string) $data['name'],
            slug: (string) $data['slug'],
            environment: (string) $data['environment'],
            hasModules: (bool) $data['has_modules'],
            hasApiKey: (bool) $data['has_api_key'],
            apiKeyFingerprint: $data['api_key_fingerprint'] ?? null,
            linkedProductionToken: $data['linked_production_token'] ?? null,
            hasPreviousApiKeyGracePeriod: (bool) $data['has_previous_api_key_grace_period'],
            apiKeyPreviousExpiresAt: isset($data['api_key_previous_expires_at']) ? CarbonImmutable::parse($data['api_key_previous_expires_at']) : null,
        );
    }
}
