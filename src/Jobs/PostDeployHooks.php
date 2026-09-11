<?php

namespace MathiasGrimm\PostDeployHooks\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use MathiasGrimm\PostDeployHooks\Contracts\HandlesFailedHooks;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Throwable;

class PostDeployHooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public readonly string $jobClass;

    public readonly CarbonImmutable $expiresAt;

    public readonly int $expires;

    public int $backoff;

    public int $tries = 0;

    public function __construct(
        public readonly string $version,
        string $job,
        ?int $expires = null,
        public readonly array $arguments = [],
    ) {
        $expires ??= config('post-deploy-hooks.job.expire');
        $backoff = config('post-deploy-hooks.job.backoff');

        $this->ensureHookOptionsAreValid($job, $expires, $backoff);

        $this->onConnection(config('post-deploy-hooks.job.connection'));
        $this->onQueue(config('post-deploy-hooks.job.queue'));

        // InteractsWithQueue reserves $job for the underlying queue message.
        $this->jobClass = $job;
        $this->expires = $expires;
        $this->backoff = $backoff;
        $this->expiresAt = CarbonImmutable::now()->addMinutes($expires);
    }

    public function retryUntil(): CarbonImmutable
    {
        return $this->expiresAt;
    }

    public function handle(): void
    {
        if (CarbonImmutable::now()->greaterThanOrEqualTo($this->expiresAt)) {
            $this->fail(new RuntimeException("Post-deploy hook for version [{$this->version}] expired."));

            return;
        }

        if ($this->version !== config('post-deploy-hooks.version')) {
            $this->release($this->backoff);

            return;
        }

        // Resolve only on the matching release, where newly deployed classes exist.
        try {
            $this->ensureTargetJobIsValid();
        } catch (ReflectionException|InvalidArgumentException $exception) {
            $this->fail($exception);

            return;
        }

        dispatch(new $this->jobClass(...$this->arguments));
    }

    public function failed(?Throwable $exception): void
    {
        $handlerClass = config('post-deploy-hooks.job.failure_handler');

        if ($handlerClass === null) {
            return;
        }

        try {
            if (! is_string($handlerClass)) {
                throw new InvalidArgumentException('post-deploy-hooks.job.failure_handler must be a handler class name.');
            }

            $handler = app($handlerClass);

            if (! $handler instanceof HandlesFailedHooks) {
                throw new InvalidArgumentException('The failure handler must implement '.HandlesFailedHooks::class.'.');
            }

            $handler->handle($this, $exception);
        } catch (Throwable $callbackException) {
            report($callbackException);
        }
    }

    private function ensureHookOptionsAreValid(string $job, mixed $expires, mixed $backoff): void
    {
        if (trim($this->version) === '' || trim($job) === '' || ! is_int($expires) || $expires < 1) {
            throw new InvalidArgumentException('A version, job class, and positive expiry in minutes are required.');
        }

        if (! is_int($backoff) || $backoff < 1) {
            throw new InvalidArgumentException('post-deploy-hooks.job.backoff must be a positive integer in seconds.');
        }

        $this->ensureArgumentKeysAreValid();
    }

    private function ensureArgumentKeysAreValid(): void
    {
        foreach (array_keys($this->arguments) as $key) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException('Job arguments must have non-empty string keys.');
            }
        }
    }

    private function ensureTargetJobIsValid(): void
    {
        $class = new ReflectionClass($this->jobClass);

        if (! $class->isInstantiable() || ! $class->implementsInterface(ShouldQueue::class)) {
            throw new InvalidArgumentException("Job [{$this->jobClass}] must be instantiable and implement ShouldQueue.");
        }

        $this->ensureTargetArgumentsAreValid($class);
    }

    private function ensureTargetArgumentsAreValid(ReflectionClass $class): void
    {
        $parameters = $class->getConstructor()?->getParameters() ?? [];
        $names = [];
        $variadic = false;

        foreach ($parameters as $parameter) {
            $names[] = $parameter->getName();
            $variadic = $variadic || $parameter->isVariadic();

            if (! $parameter->isOptional() && ! $parameter->isVariadic()
                && ! array_key_exists($parameter->getName(), $this->arguments)) {
                throw new InvalidArgumentException("Missing job argument [{$parameter->getName()}].");
            }
        }

        if (! $variadic && array_diff(array_keys($this->arguments), $names) !== []) {
            throw new InvalidArgumentException("Unknown constructor arguments for job [{$this->jobClass}].");
        }
    }
}
