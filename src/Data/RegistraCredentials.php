<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Data;

final readonly class RegistraCredentials
{
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
        public string $environment,
    ) {}
}
