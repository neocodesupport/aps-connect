<?php

declare(strict_types=1);

namespace Neocode\ApsConnect;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Neocode\ApsConnect\Console\Commands\ApsConnectDoctorCommand;
use Neocode\ApsConnect\Console\Commands\ApsConnectInstallCommand;
use Neocode\ApsConnect\Console\Commands\ApsConnectReleaseCommand;
use Neocode\ApsConnect\Data\AppStationCredentials;
use Neocode\ApsConnect\Data\RegistraCredentials;
use Neocode\ApsConnect\Http\AppStationClient;
use Neocode\ApsConnect\Http\RegistraClient;
use Neocode\ApsConnect\Support\EnvironmentFileInstaller;
use Neocode\ApsConnect\Support\ProjectConfigReader;
use Neocode\ApsConnect\Support\ReleaseArchiveBuilder;

class ApsConnectServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            ProjectConfigReader::class,
            fn (Application $app) => new ProjectConfigReader($app->basePath()),
        );

        $this->app->singleton(
            RegistraCredentials::class,
            fn (Application $app) => $app->make(ProjectConfigReader::class)->credentials(),
        );

        $this->app->singleton(
            RegistraClient::class,
            fn (Application $app) => new RegistraClient($app->make(RegistraCredentials::class), $app->make(HttpFactory::class)),
        );

        $this->app->singleton(
            AppStationCredentials::class,
            fn (Application $app) => $app->make(ProjectConfigReader::class)->appStationCredentials($app->make(RegistraCredentials::class)),
        );

        $this->app->singleton(
            AppStationClient::class,
            fn (Application $app) => new AppStationClient($app->make(AppStationCredentials::class), $app->make(HttpFactory::class)),
        );

        $this->app->singleton(ApsConnect::class);

        $this->app->singleton(
            ReleaseArchiveBuilder::class,
            fn (Application $app) => new ReleaseArchiveBuilder($app->basePath()),
        );

        $this->app->singleton(
            EnvironmentFileInstaller::class,
            fn (Application $app) => new EnvironmentFileInstaller($app->basePath()),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ApsConnectDoctorCommand::class,
            ApsConnectReleaseCommand::class,
            ApsConnectInstallCommand::class,
        ]);
    }
}
