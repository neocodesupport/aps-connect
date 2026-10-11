<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands;

use Illuminate\Console\Command;
use Neocode\ApsConnect\Support\ApsCliSession;

/**
 * PHP counterpart of `aps logout`: deletes the stored publisher session —
 * shared with aps-cli, so this logs both tools out.
 */
class ApsConnectLogoutCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'aps-connect:logout';

    /**
     * The command description.
     */
    protected $description = 'Log out of App Station (deletes the session shared with the aps CLI) — like "aps logout".';

    /**
     * Execute the console command.
     */
    public function handle(ApsCliSession $session): int
    {
        $this->components->info($session->clear()
            ? 'Déconnecté. Credentials locaux supprimés (aps-cli compris).'
            : 'Aucune session enregistrée : déjà déconnecté.');

        return self::SUCCESS;
    }
}
