<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Support\Facades\DB;
use MathiasGrimm\PostDeployHooks\Jobs\PostDeployHooks;
use MathiasGrimm\PostDeployHooks\Tests\Fixtures\FailureHandler;
use MathiasGrimm\PostDeployHooks\Tests\Fixtures\GenerateSitemap;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('enqueues through the real console entry point with a fixed default deadline', function () {
    $this->freezeSecond();
    $output = new BufferedOutput;
    $status = app(Kernel::class)->handle(new ArgvInput([
        'artisan', 'post-deploy-hooks', '--deploy-version=release-b', '--job='.GenerateSitemap::class,
    ]), $output);

    expect($status)->toBe(0);
    expect($output->fetch())->toContain('Post-deploy hook queued');
    $payload = json_decode(DB::table('jobs')->sole()->payload, true);
    $hook = unserialize($payload['data']['command']);
    expect($hook)->toBeInstanceOf(PostDeployHooks::class)
        ->version->toBe('release-b')
        ->jobClass->toBe(GenerateSitemap::class)
        ->expires->toBe(30);
    expect($payload['retryUntil'])->toBe(now()->addMinutes(30)->timestamp);
});

it('accepts a target that only exists in the upcoming release and routes the wrapper', function () {
    $this->artisan('post-deploy-hooks', [
        '--deploy-version' => 'release-b', '--job' => 'App\\Jobs\\NewJob',
        '--expires' => '60', '--connection' => 'database', '--queue' => 'deployments',
    ])->assertSuccessful();

    $row = DB::table('jobs')->sole();
    $hook = unserialize(json_decode($row->payload, true)['data']['command']);
    expect($row->queue)->toBe('deployments');
    expect($hook->expires)->toBe(60);
});

it('rejects invalid command input without enqueueing', function (array $options) {
    $this->artisan('post-deploy-hooks', array_merge([
        '--deploy-version' => 'release-b', '--job' => GenerateSitemap::class,
    ], $options))->assertFailed();
    expect(DB::table('jobs')->count())->toBe(0);
})->with([
    [['--deploy-version' => '']], [['--job' => ' ']], [['--expires' => '0']],
    [['--expires' => '-1']], [['--expires' => '1.5']], [['--expires' => 'abc']],
    [['--expires' => '999999999999999999999']],
    [['--queue' => '']],
]);

it('allows sync and immediately dispatches the target when the version matches', function () {
    config(['queue.connections.immediate' => ['driver' => 'sync']]);
    $this->artisan('post-deploy-hooks', [
        '--deploy-version' => 'release-b', '--job' => GenerateSitemap::class,
        '--connection' => 'immediate',
    ])->assertSuccessful();
    $payload = json_decode(DB::table('jobs')->sole()->payload, true);
    expect($payload['displayName'])->toBe(GenerateSitemap::class);
});

it('cannot retry or expire a version mismatch on sync', function () {
    $handler = new FailureHandler;
    app()->instance(FailureHandler::class, $handler);
    config([
        'queue.connections.immediate' => ['driver' => 'sync'],
        'post-deploy-hooks.job.failure_handler' => FailureHandler::class,
        'post-deploy-hooks.version' => 'release-a',
    ]);
    $this->artisan('post-deploy-hooks', [
        '--deploy-version' => 'release-b', '--job' => GenerateSitemap::class,
        '--connection' => 'immediate', '--expires' => 1,
    ])->assertSuccessful();
    $this->travel(2)->minutes();
    $this->work();
    expect(DB::table('jobs')->count())->toBe(0);
    expect($handler->calls)->toBeEmpty();
});

it('allows the null driver', function () {
    config(['queue.connections.discard' => ['driver' => 'null']]);
    $this->artisan('post-deploy-hooks', [
        '--deploy-version' => 'release-b', '--job' => GenerateSitemap::class,
        '--connection' => 'discard',
    ])->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(0);
});

it('allows failover to a persistent connection', function () {
    config(['queue.connections.fallback' => [
        'driver' => 'failover', 'connections' => ['missing', 'database'],
    ]]);
    $this->artisan('post-deploy-hooks', [
        '--deploy-version' => 'release-b', '--job' => GenerateSitemap::class,
        '--connection' => 'fallback',
    ])->assertSuccessful();
    expect(json_decode(DB::table('jobs')->sole()->payload, true)['displayName'])->toBe(PostDeployHooks::class);
    $this->work();
    expect(json_decode(DB::table('jobs')->sole()->payload, true)['displayName'])->toBe(GenerateSitemap::class);
});

it('accepts a custom driver registered with Laravel', function () {
    app('queue')->extend('custom-database', fn () => new DatabaseConnector(app('db')));
    config(['queue.connections.custom' => array_merge(config('queue.connections.database'), [
        'driver' => 'custom-database',
    ])]);
    $this->artisan('post-deploy-hooks', [
        '--deploy-version' => 'release-b', '--job' => GenerateSitemap::class,
        '--connection' => 'custom',
    ])->assertSuccessful();
    expect(json_decode(DB::table('jobs')->sole()->payload, true)['displayName'])->toBe(PostDeployHooks::class);
    $this->work();
    expect(json_decode(DB::table('jobs')->sole()->payload, true)['displayName'])->toBe(GenerateSitemap::class);
});

it('uses configured routing unless explicitly overridden', function (bool $fromConsole, bool $override) {
    config([
        'queue.connections.hooks' => config('queue.connections.database'),
        'post-deploy-hooks.job.connection' => 'hooks',
        'post-deploy-hooks.job.queue' => 'configured-hooks',
    ]);

    if ($fromConsole) {
        $this->artisan('post-deploy-hooks', array_merge([
            '--deploy-version' => 'release-b', '--job' => GenerateSitemap::class,
        ], $override ? ['--connection' => 'database', '--queue' => 'explicit-hooks'] : []))
            ->assertSuccessful();
    } elseif ($override) {
        PostDeployHooks::dispatch('release-b', GenerateSitemap::class)
            ->onConnection('database')->onQueue('explicit-hooks');
    } else {
        PostDeployHooks::dispatch('release-b', GenerateSitemap::class);
    }

    $row = DB::table('jobs')->sole();
    $hook = unserialize(json_decode($row->payload, true)['data']['command']);
    expect($row->queue)->toBe($override ? 'explicit-hooks' : 'configured-hooks');
    expect($hook->connection)->toBe($override ? 'database' : 'hooks');

    $this->work($row->queue);
    $target = DB::table('jobs')->sole();
    expect($target->queue)->toBe('sitemaps');
    expect(json_decode($target->payload, true)['displayName'])->toBe(GenerateSitemap::class);
})->with([true, false])->with([true, false]);
