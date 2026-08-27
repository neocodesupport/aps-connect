<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

final readonly class AppStationCredentials
{
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
    ) {}
}
