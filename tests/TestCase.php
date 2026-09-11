<?php

namespace MathiasGrimm\PostDeployHook\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Schema;
use MathiasGrimm\PostDeployHook\PostDeployHookServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    public array $failures = [];

    protected function getPackageProviders($app): array
    {
        return [PostDeployHookServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
        ]);
        $app['config']->set('post-deploy-hook.version', 'release-b');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $this->app['events']->listen(JobFailed::class, function (JobFailed $event) {
            $this->failures[] = $event;
        });
    }

    public function work(string $queue = 'default'): void
    {
        $this->app['queue.worker']->runNextJob('database', $queue, new WorkerOptions(sleep: 0, maxTries: 1));
    }
}
