<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Neocode\ApsConnect\Console\Commands\Concerns\PromptsMissingOptions;
use Neocode\ApsConnect\Events\ApsConnectInstalled;
use Neocode\ApsConnect\Events\ApsConnectInstalling;
use Neocode\ApsConnect\Support\EnvironmentFileInstaller;

/**
 * Run once by the customer after extracting an `aps-connect:release --pack`
 * zip: `.env*` is deliberately excluded from that zip (see
 * ReleaseArchiveBuilder), so the app can't boot at all until this runs.
 * Also doubles as the update path — when `.env` already exists this skips
 * straight to key:generate/migrate/storage:link, exactly what a redeployed
 * zip needs, with no separate "update" command required.
 *
 * Deliberately split across two runs when `.env` doesn't exist yet: Laravel
 * resolves `config('database.connections.*')` from `.env` at boot, before
 * this command even runs, so writing new DB_* values to `.env` partway
 * through this process would NOT be visible to `migrate` in that same
 * process. Rather than fight that with manual config()/putenv() overrides
 * (easy to get subtly wrong), the first run writes `.env` and stops; the
 * second run boots fresh with it already in place.
 */
class ApsConnectInstallCommand extends Command
{
    use PromptsMissingOptions;

    private const array DB_CONNECTIONS = ['sqlite', 'mysql', 'pgsql'];

    private const array DEFAULT_DB_PORTS = ['mysql' => '3306', 'pgsql' => '5432'];

    /**
     * The command signature.
     */
    protected $signature = 'aps-connect:install
        {--app-url= : APP_URL (only used when .env does not exist yet)}
        {--db-connection= : sqlite|mysql|pgsql (only used when .env does not exist yet)}
        {--db-host= : Only used for mysql/pgsql}
        {--db-port= : Only used for mysql/pgsql}
        {--db-database= : Only used for mysql/pgsql}
        {--db-username= : Only used for mysql/pgsql}
        {--db-password= : Only used for mysql/pgsql}
        {--no-migrate : Skip running migrations}
        {--no-storage-link : Skip storage:link}';

    /**
     * The command description.
     */
    protected $description = 'Finish installing (or update) this deployment: writes .env on the first run, then generates the app key, migrates, and links storage.';

    /**
     * Execute the console command.
     */
    public function handle(EnvironmentFileInstaller $env): int
    {
        Event::dispatch(new ApsConnectInstalling);

        if (! $env->envExists()) {
            $this->setUpEnvironment($env);

            $this->components->info(
                '.env écrit. Relancez "php artisan aps-connect:install" pour terminer l\'installation.',
            );

            return self::SUCCESS;
        }

        $this->finishInstall();

        Event::dispatch(new ApsConnectInstalled);

        $this->components->info('Installation terminée.');

        return self::SUCCESS;
    }

    private function setUpEnvironment(EnvironmentFileInstaller $env): void
    {
        $env->createFromExample();

        $appUrl = $this->stringOption('app-url') ?? $this->askWithDefault(
            'APP_URL (ex. https://mon-logiciel.exemple.com)',
            'http://localhost',
        );

        $connection = $this->stringOption('db-connection') ?? $this->choiceString(
            'Connexion base de données',
            self::DB_CONNECTIONS,
            'sqlite',
        );

        $values = ['APP_URL' => $appUrl, 'DB_CONNECTION' => $connection];

        if ($connection === 'sqlite') {
            $env->setValues($values);
            $env->ensureSqliteFileExists();

            return;
        }

        $values['DB_HOST'] = $this->stringOption('db-host') ?? $this->askWithDefault('DB_HOST', '127.0.0.1');
        $values['DB_PORT'] = $this->stringOption('db-port') ?? $this->askWithDefault('DB_PORT', self::DEFAULT_DB_PORTS[$connection]);
        $values['DB_DATABASE'] = $this->stringOption('db-database') ?? $this->askWithDefault('DB_DATABASE', '');
        $values['DB_USERNAME'] = $this->stringOption('db-username') ?? $this->askWithDefault('DB_USERNAME', '');
        $values['DB_PASSWORD'] = $this->stringOption('db-password') ?? $this->secretIfInteractive('DB_PASSWORD') ?? '';

        $env->setValues($values);
    }

    private function finishInstall(): void
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            Artisan::call('key:generate', ['--force' => true]);
        }

        if (! $this->option('no-migrate')) {
            $this->components->info('Migration de la base de données...');
            Artisan::call('migrate', ['--force' => true]);
            $this->line(Artisan::output());
        }

        if (! $this->option('no-storage-link')) {
            Artisan::call('storage:link');
        }
    }
}
