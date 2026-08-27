<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

final readonly class Tag
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
            slug: (string) $data['slug'],
        );
    }
}
