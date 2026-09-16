<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched once `aps-connect:install` has actually completed every step
 * (APP_KEY generated if it was missing, migrations run, storage:link run)
 * — never on a run that only wrote `.env` and stopped (see
 * ApsConnectInstallCommand for why that split exists). This is the hook a
 * software's own EventServiceProvider should listen to for its own
 * post-install steps (seeding an admin account, warming a cache, ...) —
 * aps-connect has no config file to declare those in, by design.
 */
final class ApsConnectInstalled
{
    use Dispatchable;
}
