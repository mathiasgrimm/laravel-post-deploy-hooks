<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use MathiasGrimm\PostDeployHook\Contracts\HandlesFailedHook;
use MathiasGrimm\PostDeployHook\Jobs\PostDeployHook;
use MathiasGrimm\PostDeployHook\Tests\Fixtures\FailureHandler;
use MathiasGrimm\PostDeployHook\Tests\Fixtures\GenerateSitemap;

it('calls the handler for permanent wrapper errors', function () {
    $handler = new FailureHandler;
    app()->instance(FailureHandler::class, $handler);
    config(['post-deploy-hook.job.failure_handler' => FailureHandler::class]);
    PostDeployHook::dispatch('release-b', 'App\\Jobs\\Missing');
    $this->work();
    expect($handler->calls)->toHaveCount(1);
    expect($handler->calls[0][1])->toBeInstanceOf(ReflectionException::class);
});

it('reports callback exceptions while preserving the original job failure', function () {
    $callbackError = new RuntimeException('Callback failed');
    $handler = Mockery::mock(HandlesFailedHook::class);
    $handler->shouldReceive('handle')->once()->andThrow($callbackError);
    app()->instance('test.failure-handler', $handler);
    config(['post-deploy-hook.job.failure_handler' => 'test.failure-handler']);

    $reporter = Mockery::mock(ExceptionHandler::class);
    $reporter->shouldReceive('report')->once()->with($callbackError);
    app()->instance(ExceptionHandler::class, $reporter);

    PostDeployHook::dispatch('release-b', stdClass::class);
    $this->work();
    expect($this->failures)->toHaveCount(1);
    expect($this->failures[0]->exception)->toBeInstanceOf(InvalidArgumentException::class);
    expect(DB::table('jobs')->count())->toBe(0);
});

it('retries dispatch errors until expiry and then calls the handler', function () {
    $this->freezeSecond();
    $handler = new FailureHandler;
    app()->instance(FailureHandler::class, $handler);
    config(['post-deploy-hook.job.failure_handler' => FailureHandler::class]);
    PostDeployHook::dispatch('release-b', UnroutableJob::class, 1);

    $this->work();
    expect($handler->calls)->toBeEmpty();
    expect(DB::table('jobs')->sole()->available_at)->toBe(now()->addSeconds(60)->timestamp);

    $this->travel(61)->seconds();
    $this->work();
    expect($handler->calls)->toHaveCount(1);
    expect(DB::table('jobs')->count())->toBe(0);
});

it('does not call the wrapper callback for a target job failure', function () {
    $handler = new FailureHandler;
    app()->instance(FailureHandler::class, $handler);
    config(['post-deploy-hook.job.failure_handler' => FailureHandler::class]);
    FailingTarget::$failed = false;
    PostDeployHook::dispatch('release-b', FailingTarget::class);
    $this->work();
    $this->work('sitemaps');
    expect(FailingTarget::$failed)->toBeTrue();
    expect($handler->calls)->toBeEmpty();
    expect($this->failures)->toHaveCount(1);
});

class UnroutableJob extends GenerateSitemap
{
    public function __construct()
    {
        $this->onConnection('does-not-exist');
    }
}

class FailingTarget extends GenerateSitemap
{
    public static bool $failed = false;

    public function handle(): void
    {
        throw new RuntimeException('Target failed');
    }

    public function failed(?Throwable $exception): void
    {
        self::$failed = true;
    }
}
