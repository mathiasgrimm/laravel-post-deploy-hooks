<?php

namespace MathiasGrimm\PostDeployHooks;

use Illuminate\Support\ServiceProvider;
use MathiasGrimm\PostDeployHooks\Commands\PostDeployHooksCommand;

class PostDeployHooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/post-deploy-hooks.php', 'post-deploy-hooks');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PostDeployHooksCommand::class]);

            $this->publishes([
                __DIR__.'/../config/post-deploy-hooks.php' => config_path('post-deploy-hooks.php'),
            ], 'post-deploy-hooks-config');
        }
    }
}
