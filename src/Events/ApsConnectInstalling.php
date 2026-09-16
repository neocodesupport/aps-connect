<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched at the start of every `aps-connect:install` run — including a
 * run that only ends up writing `.env` and stopping there (see
 * ApsConnectInstallCommand). Listen for this if a step needs to run before
 * the rest of installation even when `.env` didn't exist yet; listen for
 * ApsConnectInstalled instead for anything that needs the app fully
 * bootable first (migrations run, key generated).
 */
final class ApsConnectInstalling
{
    use Dispatchable;
}
