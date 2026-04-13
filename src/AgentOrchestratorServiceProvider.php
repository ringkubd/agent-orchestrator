<?php

namespace Anwar\AgentOrchestrator;

use Illuminate\Support\ServiceProvider;
use Anwar\AgentOrchestrator\Services\QdrantService;
use Anwar\AgentOrchestrator\Services\VectorService;
use Anwar\AgentOrchestrator\Services\ContextManager;
use Anwar\AgentOrchestrator\Services\AiSearchService;
use Anwar\AgentOrchestrator\Console\Commands\SyncChefBrain;
use Anwar\AgentOrchestrator\Console\Commands\SyncProductsToQdrant;

class AgentOrchestratorServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/agent.php', 'agent'
        );

        $this->app->singleton(QdrantService::class, function ($app) {
            return new QdrantService();
        });

        $this->app->singleton(VectorService::class, function ($app) {
            return new VectorService();
        });

        $this->app->singleton(ContextManager::class, function ($app) {
            return new ContextManager();
        });

        $this->app->singleton(AiAgentService::class, function ($app) {
            return new AiAgentService(
                $app->make(ContextManager::class),
                $app->make(AiSearchService::class),
                $app->make(VectorService::class)
            );
        });

        $this->app->singleton(AiSearchService::class, function ($app) {
            return new AiSearchService(
                $app->make(QdrantService::class),
                $app->make(VectorService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/agent.php' => config_path('agent.php'),
        ], 'agent-config');

        // Load Routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // Register Commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncChefBrain::class,
                SyncProductsToQdrant::class,
            ]);
        }
    }
}
