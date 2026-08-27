<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

final readonly class AppStationCredentials
{
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
    ) {}
}
