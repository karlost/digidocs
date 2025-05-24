<?php

namespace Digihood\Digidocs;

use Illuminate\Support\ServiceProvider;
use Digihood\Digidocs\Services\MemoryService;
use Digihood\Digidocs\Agent\DocumentationAgent;
use Digihood\Digidocs\Commands\AutoDocsCommand;

class DigidocsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/digidocs.php',
            'digidocs'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../config/pricing.php',
            'digidocs.pricing'
        );

        // Registrace služeb
        $this->app->singleton(MemoryService::class, function ($app) {
            return new MemoryService();
        });

        $this->app->singleton(DocumentationAgent::class, function ($app) {
            return new DocumentationAgent();
        });

        $this->app->singleton(Services\GitWatcherService::class, function ($app) {
            return new Services\GitWatcherService();
        });

        $this->app->singleton(Services\CostTracker::class, function ($app) {
            return new Services\CostTracker($app->make(MemoryService::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registrace artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                AutoDocsCommand::class,
                Commands\WatchCommand::class,
            ]);
        }

        // Publikace konfiguračních souborů
        $this->publishes([
            __DIR__.'/../config/digidocs.php' => config_path('digidocs.php'),
            __DIR__.'/../config/pricing.php' => config_path('digidocs/pricing.php'),
        ], 'digidocs-config');

        // Publikace views
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/digidocs'),
        ], 'digidocs-views');

        // Publikace migrací
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'digidocs-migrations');

        // Načtení views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'digidocs');

        // Načtení migrací
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
