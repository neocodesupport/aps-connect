<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Data;

/**
 * Distinct from RegistraCredentials/AppStationCredentials: this carries the
 * App Station *publisher session* token (`aps login` / `APS_TOKEN`), not a
 * per-project runtime API key. It is deliberately never container-bound —
 * see ApsConnectReleaseCommand — since the token is resolved per invocation
 * from a CLI flag, env var, or the `aps login` session (ApsCliSession), not
 * from appstation.conf.json.
 */
final readonly class PublisherCredentials
{
    public function __construct(
        public string $token,
        public string $baseUrl,
    ) {}
}
