<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use MathiasGrimm\PostDeployHooks\Jobs\PostDeployHooks;
use MathiasGrimm\PostDeployHooks\Tests\Fixtures\FailureHandler;
use MathiasGrimm\PostDeployHooks\Tests\Fixtures\GenerateSitemap;

it('releases for sixty seconds and then dispatches on the matching release', function () {
    $this->freezeSecond();
    config(['post-deploy-hooks.version' => 'release-a']);
    PostDeployHooks::dispatch('release-b', GenerateSitemap::class);
    $deadline = now()->addMinutes(30)->timestamp;

    $this->work();
    $row = DB::table('jobs')->sole();
    expect($row->available_at)->toBe(now()->addSeconds(60)->timestamp);
    expect(json_decode($row->payload, true)['retryUntil'])->toBe($deadline);

    $this->travel(59)->seconds();
    $this->work();
    expect(DB::table('jobs')->sole()->attempts)->toBe(1);

    $this->travel(1)->seconds();
    $this->work();
    expect(DB::table('jobs')->sole()->attempts)->toBe(2);
    expect($this->failures)->toBeEmpty();

    config(['post-deploy-hooks.version' => 'release-b']);
    $this->travel(60)->seconds();
    $this->work();
    $target = DB::table('jobs')->sole();
    expect($target->queue)->toBe('sitemaps');
    expect(json_decode($target->payload, true)['displayName'])->toBe(GenerateSitemap::class);
    expect($this->failures)->toBeEmpty();
});

it('does not treat missing or loosely equal versions as a match', function ($current, string $expected) {
    config(['post-deploy-hooks.version' => $current]);
    Queue::fake();
    $hook = (new PostDeployHooks($expected, GenerateSitemap::class))->withFakeQueueInteractions();
    $hook->handle();
    $hook->assertReleased(60);
    Queue::assertNothingPushed();
})->with([[null, 'release-b'], ['0e123', '0e456'], [123, '123']]);

it('preserves the deadline across serialization and elapsed time', function () {
    $this->freezeSecond();
    $hook = new PostDeployHooks('release-b', GenerateSitemap::class);
    $deadline = $hook->retryUntil()->timestamp;
    $this->travel(29)->minutes();
    $restored = unserialize(serialize($hook));
    expect($restored->retryUntil()->timestamp)->toBe($deadline);
});

it('uses configured backoff for mismatch and captures it in the queue payload', function () {
    $this->freezeSecond();
    config(['post-deploy-hooks.job.backoff' => 15, 'post-deploy-hooks.version' => 'release-a']);
    PostDeployHooks::dispatch('release-b', GenerateSitemap::class);
    config(['post-deploy-hooks.job.backoff' => 99]);
    $this->work();
    $row = DB::table('jobs')->sole();
    expect($row->available_at)->toBe(now()->addSeconds(15)->timestamp);
    expect(json_decode($row->payload, true)['backoff'])->toBe('15');
});

it('fails at or after the deadline even if the version now matches and calls the configured handler', function (int $seconds) {
    $this->freezeSecond();
    $handler = new FailureHandler;
    app()->instance(FailureHandler::class, $handler);
    config(['post-deploy-hooks.job.failure_handler' => FailureHandler::class]);
    PostDeployHooks::dispatch('release-b', GenerateSitemap::class, 1);

    $this->travel($seconds)->seconds();
    $this->work();

    expect(DB::table('jobs')->count())->toBe(0);
    expect($this->failures)->toHaveCount(1);
    expect($handler->calls)->toHaveCount(1);
    expect($handler->calls[0][0])->version->toBe('release-b')->jobClass->toBe(GenerateSitemap::class);
    expect($handler->calls[0][1])->toBe($this->failures[0]->exception);
})->with([60, 61, 3600]);

it('does not resolve missing target classes on an old release', function () {
    config(['post-deploy-hooks.version' => 'release-a']);
    PostDeployHooks::dispatch('release-b', 'App\\Jobs\\NewJob');
    $this->work();
    expect(DB::table('jobs')->count())->toBe(1);
    expect($this->failures)->toBeEmpty();
});

it('permanently fails invalid targets on a matching release', function (string $target) {
    PostDeployHooks::dispatch('release-b', $target);
    $this->work();
    expect(DB::table('jobs')->count())->toBe(0);
    expect($this->failures)->toHaveCount(1);
})->with(['App\\Jobs\\Missing', stdClass::class, RequiresArguments::class]);

class RequiresArguments implements ShouldQueue
{
    public function __construct(string $value) {}
}
