<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

final readonly class ModuleVerification
{
    public function __construct(
        public string $licenceKey,
        public string $moduleSlug,
        public LicenceStatus $mother,
        public ModuleEntitlement $module,
        public ?string $message,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $licenceKey = (string) $data['licence_key'];

        return new self(
            licenceKey: $licenceKey,
            moduleSlug: (string) $data['module_slug'],
            mother: LicenceStatus::fromArray([...$data['mother'], 'licence_key' => $licenceKey]),
            module: ModuleEntitlement::fromArray($data['module']),
            message: $data['message'] ?? null,
        );
    }
}
