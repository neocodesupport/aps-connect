<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

final readonly class Customer
{
    public function __construct(
        public ?int $id,
        public string $email,
        public ?string $firstname,
        public ?string $lastname,
        public ?string $status,
        public ?string $phone,
        public ?string $address,
        public ?string $softwareKey,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            email: (string) $data['email'],
            firstname: $data['firstname'] ?? null,
            lastname: $data['lastname'] ?? null,
            status: $data['status'] ?? null,
            phone: $data['phone'] ?? null,
            address: $data['address'] ?? null,
            softwareKey: $data['software_key'] ?? null,
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
