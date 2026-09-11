<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use MathiasGrimm\PostDeployHook\Jobs\PostDeployHook;
use MathiasGrimm\PostDeployHook\Tests\Fixtures\GenerateSitemap;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('enqueues through the real console entry point with a fixed default deadline', function () {
    $this->freezeSecond();
    $output = new BufferedOutput;
    $status = app(Kernel::class)->handle(new ArgvInput([
        'artisan', 'post-deploy-hook', '--deploy-version=release-b', '--job='.GenerateSitemap::class,
    ]), $output);

    expect($status)->toBe(0);
    expect($output->fetch())->toContain('Post-deploy hook queued');
    $payload = json_decode(DB::table('jobs')->sole()->payload, true);
    $hook = unserialize($payload['data']['command']);
    expect($hook)->toBeInstanceOf(PostDeployHook::class)
        ->version->toBe('release-b')
        ->jobClass->toBe(GenerateSitemap::class)
        ->expires->toBe(30);
    expect($payload['retryUntil'])->toBe(now()->addMinutes(30)->timestamp);
});

it('accepts a target that only exists in the upcoming release and routes the wrapper', function () {
    $this->artisan('post-deploy-hook', [
        '--deploy-version' => 'release-b', '--job' => 'App\\Jobs\\NewJob',
        '--expires' => '60', '--connection' => 'database', '--queue' => 'deployments',
    ])->assertSuccessful();

    $row = DB::table('jobs')->sole();
    $hook = unserialize(json_decode($row->payload, true)['data']['command']);
    expect($row->queue)->toBe('deployments');
    expect($hook->expires)->toBe(60);
});

it('rejects invalid command input without enqueueing', function (array $options) {
    $this->artisan('post-deploy-hook', array_merge([
        '--deploy-version' => 'release-b', '--job' => GenerateSitemap::class,
    ], $options))->assertFailed();
    expect(DB::table('jobs')->count())->toBe(0);
})->with([
    [['--deploy-version' => '']], [['--job' => ' ']], [['--expires' => '0']],
    [['--expires' => '-1']], [['--expires' => '1.5']], [['--expires' => 'abc']],
    [['--expires' => '999999999999999999999']], [['--connection' => 'missing']],
    [['--queue' => '']],
]);

it('rejects connections that cannot durably release the wrapper', function (string $driver) {
    config(['queue.connections.unsafe' => ['driver' => $driver]]);
    $this->artisan('post-deploy-hook', [
        '--deploy-version' => 'release-b', '--job' => GenerateSitemap::class,
        '--connection' => 'unsafe',
    ])->assertFailed();
    expect(DB::table('jobs')->count())->toBe(0);
})->with(['sync', 'null', 'deferred', 'background', 'failover']);
