<?php

namespace MathiasGrimm\PostDeployHooks\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use MathiasGrimm\PostDeployHooks\Jobs\PostDeployHooks;

class PostDeployHooksCommand extends Command
{
    private const INVALID_OPTIONS_MESSAGE = 'Provide --deploy-version, --job, and a positive integer for --expires (minutes).';

    protected $signature = 'post-deploy-hooks
        {--deploy-version= : The release version to wait for}
        {--job= : The fully qualified application job class}
        {--expires= : Maximum waiting time in minutes (defaults to config)}
        {--with=* : Named job argument as key=value; repeat for multiple arguments}
        {--connection= : Queue connection for the wrapper}
        {--queue= : Queue name for the wrapper}';

    protected $description = 'Queue a job once a worker is running the expected deployment version';

    public function handle(): int
    {
        try {
            $options = $this->validatedOptions();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        PostDeployHooks::dispatch(
            $options->version,
            $options->job,
            $options->expires,
            $options->arguments,
        )
            ->onConnection($options->connection)
            ->onQueue($options->queue);

        $this->info("Post-deploy hook queued for version [{$options->version}]. Expires in {$options->expires} minutes.");

        return self::SUCCESS;
    }

    private function validatedOptions(): PostDeployHooksOptions
    {
        $version = $this->option('deploy-version');
        $job = $this->option('job');
        $expires = $this->option('expires')
            ?? config('post-deploy-hooks.job.expire', 30);

        $this->ensureVersionIsValid($version);
        $this->ensureJobIsValid($job);
        $expires = $this->ensureExpiresIsValid($expires);

        $connection = $this->option('connection') ?? config('queue.default');
        $queue = $this->option('queue');

        $this->ensureQueueIsValid($queue);

        return new PostDeployHooksOptions(
            version: $version,
            job: $job,
            expires: $expires,
            arguments: $this->parseJobArguments(),
            connection: $connection,
            queue: $queue,
        );
    }

    private function ensureVersionIsValid(mixed $version): void
    {
        if (! is_string($version) || trim($version) === '') {
            throw new InvalidArgumentException(self::INVALID_OPTIONS_MESSAGE);
        }
    }

    private function ensureJobIsValid(mixed $job): void
    {
        if (! is_string($job) || trim($job) === '') {
            throw new InvalidArgumentException(self::INVALID_OPTIONS_MESSAGE);
        }
    }

    private function ensureExpiresIsValid(mixed $expires): int
    {
        $expires = filter_var($expires, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($expires === false) {
            throw new InvalidArgumentException(self::INVALID_OPTIONS_MESSAGE);
        }

        return $expires;
    }

    private function ensureQueueIsValid(?string $queue): void
    {
        if ($queue !== null && trim($queue) === '') {
            throw new InvalidArgumentException('--queue must not be empty.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function parseJobArguments(): array
    {
        $arguments = [];

        foreach ($this->option('with') as $pair) {
            $parts = explode('=', $pair, 2);
            $key = $parts[0];

            $this->ensureJobArgumentIsValid($key, $parts[1] ?? null, $arguments);

            $arguments[$key] = $parts[1];
        }

        return $arguments;
    }

    /**
     * @param  array<string, string>  $arguments
     */
    private function ensureJobArgumentIsValid(string $key, ?string $value, array $arguments): void
    {
        if ($value === null || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)
            || array_key_exists($key, $arguments)) {
            throw new InvalidArgumentException('Each --with must be key=value with a unique named argument key.');
        }
    }
}
