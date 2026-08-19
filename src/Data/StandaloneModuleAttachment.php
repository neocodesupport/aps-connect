<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

final readonly class StandaloneModuleAttachment
{
    /**
     * @param  list<ModuleEntitlement>  $modules
     */
    public function __construct(
        public string $motherLicenceKey,
        public array $modules,
        public ?string $message,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            motherLicenceKey: (string) $data['mother_licence_key'],
            modules: ModuleEntitlement::collectionFromArray($data['modules'] ?? []),
            message: $data['message'] ?? null,
        );
    }
}
