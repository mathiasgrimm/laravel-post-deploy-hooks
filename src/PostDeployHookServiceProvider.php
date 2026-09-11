<?php

namespace MathiasGrimm\PostDeployHook;

use Illuminate\Support\ServiceProvider;
use MathiasGrimm\PostDeployHook\Commands\PostDeployHookCommand;

class PostDeployHookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/post-deploy-hook.php', 'post-deploy-hook');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PostDeployHookCommand::class]);

            $this->publishes([
                __DIR__.'/../config/post-deploy-hook.php' => config_path('post-deploy-hook.php'),
            ], 'post-deploy-hook-config');
        }
    }
}
