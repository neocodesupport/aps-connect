<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

/**
 * Distinct from RegistraCredentials/AppStationCredentials: this carries the
 * App Station *publisher session* token (`aps login` / `APS_TOKEN`), not a
 * per-project runtime API key. It is deliberately never container-bound —
 * see ApsConnectReleasePublishCommand — since the token is resolved per
 * invocation from a CLI flag or env var, not from appstation.conf.json.
 */
final readonly class PublisherCredentials
{
    public function __construct(
        public string $token,
        public string $baseUrl,
    ) {}
}
