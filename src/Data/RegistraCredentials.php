<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

final readonly class RegistraCredentials
{
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
        public string $environment,
    ) {}
}
