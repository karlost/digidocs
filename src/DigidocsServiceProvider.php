<?php

namespace Digihood\Digidocs;

use Illuminate\Support\ServiceProvider;
use Digihood\Digidocs\Services\MemoryService;
use Digihood\Digidocs\Services\SimpleLanguageHelper;
use Digihood\Digidocs\Agent\DocumentationAgent;
use Digihood\Digidocs\Agent\UserDocumentationOrchestrator;
use Digihood\Digidocs\Agent\UserJourneyMapper;
use Digihood\Digidocs\Agent\CrossReferenceManager;
use Digihood\Digidocs\Services\SimpleDocumentationMemory;
use Digihood\Digidocs\Services\RAGDocumentationMemory;
use Digihood\Digidocs\Services\CodeDocumentationMemory;
use Digihood\Digidocs\Services\DocumentationStructureAnalyzer;
use Digihood\Digidocs\Agent\ChangeAnalysisAgent;
use Digihood\Digidocs\Agent\UserChangeAnalysisAgent;
use Digihood\Digidocs\Commands\AutoDocsCommand;
use Digihood\Digidocs\Commands\AllDocsCommand;

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
            __DIR__.'/../config/prompts.php',
            'digidocs.prompts'
        );

        // Registrace služeb
        $this->app->singleton(MemoryService::class, function ($app) {
            return new MemoryService();
        });
        
        $this->app->singleton(SimpleLanguageHelper::class, function ($app) {
            return new SimpleLanguageHelper();
        });

        // Registrace CodeDocumentationMemory před agenty
        $this->app->singleton(CodeDocumentationMemory::class, function ($app) {
            return new CodeDocumentationMemory();
        });

        $this->app->singleton(DocumentationAgent::class, function ($app) {
            $agent = new DocumentationAgent();
            if ($app->bound(CodeDocumentationMemory::class)) {
                $agent->setMemory($app->make(CodeDocumentationMemory::class));
            }
            return $agent;
        });

        $this->app->singleton(UserDocumentationOrchestrator::class, function ($app) {
            return new UserDocumentationOrchestrator();
        });

        $this->app->singleton(ChangeAnalysisAgent::class, function ($app) {
            $agent = new ChangeAnalysisAgent();
            if ($app->bound(CodeDocumentationMemory::class)) {
                $agent->setMemory($app->make(CodeDocumentationMemory::class));
            }
            return $agent;
        });

        $this->app->singleton(UserChangeAnalysisAgent::class, function ($app) {
            return new UserChangeAnalysisAgent();
        });

        $this->app->singleton(Services\GitWatcherService::class, function ($app) {
            return new Services\GitWatcherService();
        });

        $this->app->singleton(Services\CostTracker::class, function ($app) {
            return new Services\CostTracker($app->make(MemoryService::class));
        });

        // Registrace nových služeb pro user dokumentaci
        $this->app->singleton(SimpleDocumentationMemory::class, function ($app) {
            return new SimpleDocumentationMemory();
        });

        $this->app->singleton(DocumentationStructureAnalyzer::class, function ($app) {
            return new DocumentationStructureAnalyzer();
        });

        $this->app->singleton(UserJourneyMapper::class, function ($app) {
            return new UserJourneyMapper();
        });

        $this->app->singleton(CrossReferenceManager::class, function ($app) {
            return new CrossReferenceManager();
        });

        $this->app->singleton(RAGDocumentationMemory::class, function ($app) {
            return new RAGDocumentationMemory();
        });
        
        // Ensure vector store directory and file exist
        $this->ensureVectorStoreExists();
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
                AllDocsCommand::class,
                Commands\WatchCommand::class,
                Commands\UserDocsCommand::class,
            ]);
        }

        // Publikace konfiguračních souborů
        $this->publishes([
            __DIR__.'/../config/digidocs.php' => config_path('digidocs.php'),
            __DIR__.'/../config/prompts.php' => config_path('digidocs/prompts.php'),
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
    
    /**
     * Ensure vector store directory and neuron.store file exist
     */
    private function ensureVectorStoreExists(): void
    {
        $vectorStorePath = storage_path('app/autodocs/vectors');
        
        // Create directory if it doesn't exist
        if (!file_exists($vectorStorePath)) {
            mkdir($vectorStorePath, 0755, true);
        }
        
        // Create neuron.store file if it doesn't exist
        $neuronStorePath = $vectorStorePath . '/neuron.store';
        if (!file_exists($neuronStorePath)) {
            // Create empty neuron.store file
            file_put_contents($neuronStorePath, '');
        }
    }
}
