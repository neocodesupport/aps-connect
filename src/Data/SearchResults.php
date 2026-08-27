<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

final readonly class SearchResults
{
    /**
     * @param  Software[]  $softwares
     * @param  Package[]  $packages
     */
    public function __construct(
        public array $softwares,
        public array $packages,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            softwares: array_map(Software::fromArray(...), $data['softwares']['data'] ?? []),
            packages: array_map(Package::fromArray(...), $data['packages']['data'] ?? []),
        );
    }
}
