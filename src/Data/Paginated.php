<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

/**
 * @template TItem
 */
final readonly class Paginated
{
    /**
     * @param  TItem[]  $items
     */
    public function __construct(
        public array $items,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {}

    /**
     * @template TMapped
     *
     * @param  array<string, mixed>  $data
     * @param  callable(array<string, mixed>): TMapped  $map
     * @return self<TMapped>
     */
    public static function fromArray(array $data, callable $map): self
    {
        $items = $data['data'] ?? [];
        $meta = $data['meta'] ?? [];

        return new self(
            items: array_map($map, $items),
            currentPage: (int) ($meta['current_page'] ?? 1),
            lastPage: (int) ($meta['last_page'] ?? 1),
            perPage: (int) ($meta['per_page'] ?? count($items)),
            total: (int) ($meta['total'] ?? count($items)),
        );
    }
}
