<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

final readonly class Category
{
    /**
     * @param  Category[]  $children
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $icon,
        public ?string $type,
        public int $position,
        public array $children,
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
            icon: $data['icon'] ?? null,
            type: $data['type'] ?? null,
            position: (int) $data['position'],
            children: array_map(self::fromArray(...), $data['children'] ?? []),
        );
    }
}
