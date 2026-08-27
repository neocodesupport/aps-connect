<?php

declare(strict_types=1);

namespace Neocode\ApsConnect;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Neocode\ApsConnect\Console\Commands\ApsConnectDoctorCommand;
use Neocode\ApsConnect\Data\AppStationCredentials;
use Neocode\ApsConnect\Data\RegistraCredentials;
use Neocode\ApsConnect\Http\AppStationClient;
use Neocode\ApsConnect\Http\RegistraClient;
use Neocode\ApsConnect\Support\ProjectConfigReader;

class ApsConnectServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/aps-connect.php', 'aps-connect');

        $this->app->singleton(
            RegistraCredentials::class,
            fn (Application $app) => (new ProjectConfigReader($app->basePath()))->credentials(),
        );

        $this->app->singleton(
            RegistraClient::class,
            fn (Application $app) => new RegistraClient($app->make(RegistraCredentials::class), $app->make(HttpFactory::class)),
        );

        $this->app->singleton(
            AppStationCredentials::class,
            fn (Application $app) => (new ProjectConfigReader($app->basePath()))->appStationCredentials($app->make(RegistraCredentials::class)),
        );

        $this->app->singleton(
            AppStationClient::class,
            fn (Application $app) => new AppStationClient($app->make(AppStationCredentials::class), $app->make(HttpFactory::class)),
        );

        $this->app->singleton(ApsConnect::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/aps-connect.php' => config_path('aps-connect.php'),
        ], ['aps-connect', 'aps-connect-config']);

        $this->commands([
            ApsConnectDoctorCommand::class,
        ]);
    }
}
