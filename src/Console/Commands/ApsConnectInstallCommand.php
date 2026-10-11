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

use function Laravel\Prompts\form;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

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

        $values = $this->environmentValues();

        if ($values['DB_CONNECTION'] === 'sqlite') {
            $env->setValues($values);
            $env->ensureSqliteFileExists();

            return;
        }

        $env->setValues($values);
    }

    /**
     * The .env values: options first; the rest asked as one Laravel Prompts
     * form (Ctrl+U goes back, keeping answers) when interactive, defaults
     * otherwise. Database details are only asked for mysql/pgsql.
     *
     * @return array<string, string>
     */
    private function environmentValues(): array
    {
        $options = [
            'APP_URL' => $this->stringOption('app-url'),
            'DB_CONNECTION' => $this->stringOption('db-connection'),
            'DB_HOST' => $this->stringOption('db-host'),
            'DB_PORT' => $this->stringOption('db-port'),
            'DB_DATABASE' => $this->stringOption('db-database'),
            'DB_USERNAME' => $this->stringOption('db-username'),
            'DB_PASSWORD' => $this->stringOption('db-password'),
        ];

        $connection = static fn (array $r): string => $options['DB_CONNECTION'] ?? $r['DB_CONNECTION'] ?? 'sqlite';
        $needsServer = static fn (array $r): bool => $connection($r) !== 'sqlite';
        $hint = 'Ctrl+U pour revenir à la question précédente.';

        $answers = ! $this->input->isInteractive() ? [] : form()
            ->addIf($options['APP_URL'] === null, fn (array $r, mixed $previous) => text(
                label: 'APP_URL',
                placeholder: 'https://mon-logiciel.exemple.com',
                default: is_string($previous) ? $previous : 'http://localhost',
            ), name: 'APP_URL')
            ->addIf($options['DB_CONNECTION'] === null, fn (array $r, mixed $previous) => select(
                label: 'Connexion base de données',
                options: self::DB_CONNECTIONS,
                default: is_string($previous) ? $previous : 'sqlite',
                hint: $hint,
            ), name: 'DB_CONNECTION')
            ->addIf(fn (array $r) => $needsServer($r) && $options['DB_HOST'] === null, fn (array $r, mixed $previous) => text(
                label: 'DB_HOST', default: is_string($previous) ? $previous : '127.0.0.1', hint: $hint,
            ), name: 'DB_HOST')
            ->addIf(fn (array $r) => $needsServer($r) && $options['DB_PORT'] === null, fn (array $r, mixed $previous) => text(
                label: 'DB_PORT', default: is_string($previous) ? $previous : (self::DEFAULT_DB_PORTS[$connection($r)] ?? ''), hint: $hint,
            ), name: 'DB_PORT')
            ->addIf(fn (array $r) => $needsServer($r) && $options['DB_DATABASE'] === null, fn (array $r, mixed $previous) => text(
                label: 'DB_DATABASE', default: is_string($previous) ? $previous : '', hint: $hint,
            ), name: 'DB_DATABASE')
            ->addIf(fn (array $r) => $needsServer($r) && $options['DB_USERNAME'] === null, fn (array $r, mixed $previous) => text(
                label: 'DB_USERNAME', default: is_string($previous) ? $previous : '', hint: $hint,
            ), name: 'DB_USERNAME')
            ->addIf(fn (array $r) => $needsServer($r) && $options['DB_PASSWORD'] === null, fn () => password(
                label: 'DB_PASSWORD', hint: $hint,
            ), name: 'DB_PASSWORD')
            ->submit();

        $value = static fn (string $key, string $default): string => $options[$key] ?? (is_string($answers[$key] ?? null) && $answers[$key] !== '' ? $answers[$key] : $default);

        $values = ['APP_URL' => $value('APP_URL', 'http://localhost'), 'DB_CONNECTION' => $value('DB_CONNECTION', 'sqlite')];

        if ($values['DB_CONNECTION'] === 'sqlite') {
            return $values;
        }

        return [
            ...$values,
            'DB_HOST' => $value('DB_HOST', '127.0.0.1'),
            'DB_PORT' => $value('DB_PORT', self::DEFAULT_DB_PORTS[$values['DB_CONNECTION']] ?? ''),
            'DB_DATABASE' => $value('DB_DATABASE', ''),
            'DB_USERNAME' => $value('DB_USERNAME', ''),
            'DB_PASSWORD' => $options['DB_PASSWORD'] ?? (is_string($answers['DB_PASSWORD'] ?? null) ? $answers['DB_PASSWORD'] : ''),
        ];
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
