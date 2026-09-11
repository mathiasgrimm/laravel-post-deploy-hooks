<?php

namespace MathiasGrimm\PostDeployHook\Commands;

use Illuminate\Console\Command;
use MathiasGrimm\PostDeployHook\Jobs\PostDeployHook;

class PostDeployHookCommand extends Command
{
    protected $signature = 'post-deploy-hook
        {--deploy-version= : The release version to wait for}
        {--job= : The fully qualified application job class}
        {--expires= : Maximum waiting time in minutes (defaults to config)}
        {--with=* : Named job argument as key=value; repeat for multiple arguments}
        {--connection= : Queue connection for the wrapper}
        {--queue= : Queue name for the wrapper}';

    protected $description = 'Queue a job once a worker is running the expected deployment version';

    public function handle(): int
    {
        $version = $this->option('deploy-version');
        $job = $this->option('job');
        $expires = filter_var($this->option('expires') ?? config('post-deploy-hook.job.expire', 30), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_string($version) || trim($version) === ''
            || ! is_string($job) || trim($job) === '' || $expires === false) {
            $this->error('Provide --deploy-version, --job, and a positive integer for --expires (minutes).');

            return self::FAILURE;
        }

        $connection = $this->option('connection') ?? config('queue.default');
        $queue = $this->option('queue');

        if ($queue !== null && trim($queue) === '') {
            $this->error('--queue must not be empty.');

            return self::FAILURE;
        }

        $arguments = [];

        foreach ($this->option('with') as $pair) {
            $parts = explode('=', $pair, 2);
            $key = $parts[0];

            if (count($parts) !== 2 || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)
                || array_key_exists($key, $arguments)) {
                $this->error('Each --with must be key=value with a unique named argument key.');

                return self::FAILURE;
            }

            $arguments[$key] = $parts[1];
        }

        PostDeployHook::dispatch($version, $job, $expires, $arguments)
            ->onConnection($connection)
            ->onQueue($queue);

        $this->info("Post-deploy hook queued for version [{$version}]. Expires in {$expires} minutes.");

        return self::SUCCESS;
    }
}
