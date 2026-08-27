<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

final readonly class SoftwareInstanceRegistration
{
    public function __construct(
        public SoftwareInstance $instance,
        public string $apiKey,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            instance: SoftwareInstance::fromArray($data['instance']),
            apiKey: (string) $data['api_key'],
        );
    }
}
