<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use MathiasGrimm\PostDeployHook\Jobs\PostDeployHook;
use MathiasGrimm\PostDeployHook\Tests\Fixtures\FailureHandler;
use MathiasGrimm\PostDeployHook\Tests\Fixtures\GenerateSitemap;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('passes repeatable key value options to the target as named constructor arguments', function () {
    $status = app(Kernel::class)->handle(new ArgvInput([
        'artisan', 'post-deploy-hook', '--deploy-version=release-b', '--job='.JobWithArguments::class,
        '--with=locale=pt-BR', '--with=siteId=123', '--with=token=a=b=c', '--with=empty=',
    ]), new BufferedOutput);
    expect($status)->toBe(0);

    $this->work();
    $target = unserialize(json_decode(DB::table('jobs')->sole()->payload, true)['data']['command']);
    expect($target)->toBeInstanceOf(JobWithArguments::class)
        ->siteId->toBe('123')->locale->toBe('pt-BR')->token->toBe('a=b=c')->empty->toBe('');
});

it('rejects malformed and duplicate pairs before enqueueing', function (array $pairs) {
    $this->artisan('post-deploy-hook', [
        '--deploy-version' => 'release-b', '--job' => JobWithArguments::class, '--with' => $pairs,
    ])->assertFailed();
    expect(DB::table('jobs')->count())->toBe(0);
})->with([[['missing-equals']], [['=value']], [['0=value']], [['siteId=1', 'siteId=2']]]);

it('retains arguments through retries for the failure callback', function () {
    $this->freezeSecond();
    $handler = new FailureHandler;
    app()->instance(FailureHandler::class, $handler);
    config(['post-deploy-hook.on_failure' => FailureHandler::class, 'post-deploy-hook.version' => 'release-a']);
    $arguments = ['siteId' => '123', 'locale' => 'en', 'token' => 'x', 'empty' => ''];
    PostDeployHook::dispatch('release-b', JobWithArguments::class, 1, $arguments);
    $this->work();
    $this->travel(61)->seconds();
    $this->work();
    expect($handler->calls)->toHaveCount(1);
    expect($handler->calls[0][0]->arguments)->toBe($arguments);
});

it('uses the configured expiry for command and direct construction while allowing overrides', function () {
    $this->freezeSecond();
    config(['post-deploy-hook.expires' => 45]);
    $hook = new PostDeployHook('release-b', GenerateSitemap::class);
    expect($hook->expires)->toBe(45);
    expect($hook->retryUntil()->timestamp)->toBe(now()->addMinutes(45)->timestamp);
    expect((new PostDeployHook('release-b', GenerateSitemap::class, 60))->expires)->toBe(60);

    $this->artisan('post-deploy-hook', [
        '--deploy-version' => 'release-b', '--job' => GenerateSitemap::class,
    ])->assertSuccessful();
    $payload = json_decode(DB::table('jobs')->sole()->payload, true);
    expect(unserialize($payload['data']['command'])->expires)->toBe(45);
    expect($payload['maxTries'])->toBe(0);

    config(['post-deploy-hook.expires' => 5]);
    expect(unserialize($payload['data']['command'])->retryUntil()->timestamp)->toBe(now()->addMinutes(45)->timestamp);
});

it('fails unknown named arguments instead of silently dropping context', function () {
    PostDeployHook::dispatch('release-b', GenerateSitemap::class, arguments: ['typo' => 'value']);
    $this->work();
    expect($this->failures)->toHaveCount(1);
    expect($this->failures[0]->exception->getMessage())->toContain('Unknown constructor arguments');
});

class JobWithArguments extends GenerateSitemap
{
    public function __construct(
        public string $siteId,
        public string $locale,
        public string $token,
        public string $empty,
    ) {
        parent::__construct();
    }
}
