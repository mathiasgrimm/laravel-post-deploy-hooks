<p align="center">
    <img src="art/banner.svg" alt="Laravel Post Deploy Hook" width="100%">
</p>

# Laravel Post Deploy Hook

> Queue deployment hooks until a worker is running the expected release.

> [!NOTE]
> This is an independent, community package. It is not an official or
> first-party Laravel package, and is not affiliated with, endorsed by, or
> sponsored by Laravel or Laravel Cloud. "Laravel" is a trademark of its
> respective owner.

Deployment commands can finish before your new queue workers are ready.
Laravel Post Deploy Hook queues a small wrapper that waits for the expected
version, then dispatches your application job through Laravel's normal bus.

```bash
php artisan post-deploy-hook \
  --deploy-version="$LARAVEL_CLOUD_COMMIT_SHA" \
  --job='App\Jobs\GenerateSitemap' \
  --expires=60
```

## Requirements

- PHP 8.2+ with Laravel 12, or PHP 8.3+ with Laravel 13.
- A shared, persistent queue using the `database`, `redis`, `sqs`, or `beanstalkd` driver.
- Running queue workers that load the version belonging to their own release.

The command rejects `sync`, `null`, `deferred`, `background`, `failover`, and custom
drivers. The wrapper needs durable delayed redelivery; choose a supported concrete
connection. Install any dependencies required by your chosen Laravel queue driver.

For an existing database queue, check the capacity of `jobs.attempts` before using
a short backoff or long expiry. Older MySQL schemas may use an unsigned tiny
integer, limited to 255 attempts. Widen that column or use another supported
driver if your retry window can exceed its capacity.

## Installation

```bash
composer require mathiasgrimm/laravel-post-deploy-hook
```

The service provider and command are discovered automatically. Optionally publish
the configuration:

```bash
php artisan vendor:publish --tag=post-deploy-hook-config
```

Before first use, deploy the package to **all workers consuming the hook queue**
and restart them. Old workers without the package cannot deserialize its wrapper.
Install your failure handler in that initial deployment too.

## Set the release version

Set `LARAVEL_POST_DEPLOY_HOOK_VERSION` separately for each release. For Laravel
Cloud, add this to the release's build commands **before** `config:cache` or
`optimize`:

```bash
test -n "$LARAVEL_CLOUD_COMMIT_SHA" || exit 1
echo "LARAVEL_POST_DEPLOY_HOOK_VERSION=$LARAVEL_CLOUD_COMMIT_SHA" >> .env
php artisan config:cache
```

Start from a release-specific `.env` with no existing definition of that key, or
replace its existing definition instead of appending another. The `>> .env`
redirection is necessary: `echo` on its own only prints the value.

Make sure the value is present in the configuration loaded by the new workers.
An existing process environment variable can take precedence over `.env`. Do not
change the marker in a shared file so that old code reports the new version.

Then enqueue the hook in your deploy commands using the same version:

```bash
php artisan post-deploy-hook \
  --deploy-version="$LARAVEL_CLOUD_COMMIT_SHA" \
  --job='App\Jobs\GenerateSitemap'
```

The producer and workers must use the same queue backend. Restart long-lived
workers as part of deployment so they load the new code and configuration.
See Laravel's [queue deployment documentation](https://laravel.com/docs/13.x/queues#queue-workers-and-deployment)
and Cloud's [deployment documentation](https://laravel.com/cloud/docs/deployments).

`--deploy-version` is intentional: Artisan reserves `--version` for displaying
the application version. Quote PHP class names in shell commands to preserve
their backslashes.

## How it works

1. The command queues `MathiasGrimm\PostDeployHook\Jobs\PostDeployHook`.
2. The wrapper compares the requested version with `config('post-deploy-hook.version')` using strict equality.
3. A missing or different version releases the wrapper for the configured backoff, 60 seconds by default, and returns without constructing your job.
4. A matching version dispatches your application job with its named arguments.
5. The wrapper stops dispatching at its deadline, 30 minutes after enqueueing by default, and fails when a worker next processes it.

The deadline is stored in the payload. Retries never extend it. `$tries = 0` and
`retryUntil()` keep the wait governed by time, including when the worker uses
`--tries=1`. Exception retries use the configured backoff too. A stopped or busy
queue can record failure later than the deadline; this is not a wall-clock timer.
If Laravel rejects an overdue wrapper before `handle()` runs, the callback receives
Laravel's `MaxAttemptsExceededException`.

The wrapper checks the **worker's version**, not website health, traffic cutover,
or completion of every deployment step. A matching wrapper dispatches a separate
job. During a rolling deployment, another worker may pick up that target job.
Use release-specific target queues if the target must run on a particular release.

Jobs should be idempotent: duplicate command invocations or queue redelivery can
dispatch the target more than once. A commit SHA identifies code, so redeploying
the same commit does not distinguish deployment attempts. Use a unique release
identifier if you need that distinction.

## Arguments and queue routing

Repeat `--with` to supply named constructor arguments:

```bash
php artisan post-deploy-hook \
  --deploy-version="$LARAVEL_CLOUD_COMMIT_SHA" \
  --job='App\Jobs\GenerateSitemap' \
  --with='siteId=123' \
  --with='locale=en' \
  --connection=redis \
  --queue=deployments
```

```php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class GenerateSitemap implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $siteId,
        public string $locale = 'en',
    ) {}

    public function handle(): void
    {
        // Generate the sitemap for this site and locale.
    }
}
```

Keys must match constructor parameter names. Values from the CLI are strings,
including `true`, `false`, and numbers; parse them explicitly if needed. Empty
values and values containing `=` are preserved. Duplicate keys, numeric keys,
and malformed pairs are rejected. Unknown or missing required constructor
arguments fail the wrapper on the matching release. The job must implement
`ShouldQueue`. Its `handle()` method can use Laravel dependency injection.

The class is not loaded or constructed on a worker with a different version, so
it can be new to the upcoming release. With no `--with` options, it must be
constructible without arguments.

`--connection` and `--queue` route **the wrapper only**. The target keeps its own
connection and queue settings, or uses Laravel's defaults. Its retries,
middleware, unique-job behavior, and `failed()` method follow normal dispatch
semantics. The wrapper's expiry does not limit the target's runtime or retries.

## Configuration

`config/post-deploy-hook.php`:

```php
return [
    'version' => env('LARAVEL_POST_DEPLOY_HOOK_VERSION'),
    'expires' => 30,  // Minutes.
    'backoff' => 60,  // Seconds between attempts.
    'on_failure' => null,
];
```

`expires` and `backoff` must be positive integers. `--expires` overrides the
configured expiry for one hook. Each wrapper captures these settings when it is
created; a later config change does not reset an existing wrapper's deadline.

You can also dispatch a hook from PHP, with typed values in the arguments array:

```php
use MathiasGrimm\PostDeployHook\Jobs\PostDeployHook;

PostDeployHook::dispatch(
    version: $release,
    job: \App\Jobs\GenerateSitemap::class,
    arguments: ['siteId' => '123', 'locale' => 'en'],
);
```

Omitting `expires`, or passing `null`, uses the configured default. Direct PHP
dispatch must also use a persistent connection; the CLI's driver validation
does not run for direct dispatch.

## Failure callback

Set `on_failure` to a class implementing `HandlesFailedHook`:

```php
'on_failure' => \App\Actions\ReportFailedDeploymentHook::class,
```

```php
namespace App\Actions;

use MathiasGrimm\PostDeployHook\Contracts\HandlesFailedHook;
use MathiasGrimm\PostDeployHook\Jobs\PostDeployHook;
use Throwable;

class ReportFailedDeploymentHook implements HandlesFailedHook
{
    public function handle(PostDeployHook $hook, ?Throwable $exception): void
    {
        logger()->error('Post-deploy hook failed', [
            'version' => $hook->version,
            'job' => $hook->jobClass,
            'arguments' => $hook->arguments,
            'expires_at' => $hook->expiresAt->toIso8601String(),
            'exception' => $exception,
        ]);
    }
}
```

The handler is resolved from Laravel's container, so constructor injection works.
Use a class name instead of a closure to keep the configuration cacheable.

The callback runs on terminal **wrapper failures**, including expiry, invalid
target classes or arguments, and exhausted construction/dispatch errors. It
does not run for successful releases, CLI validation errors, or failures in the
application job after it has been dispatched. Handle those in the target's own
`failed()` method.

Callbacks use the failing worker's configuration and a fresh copy of the wrapper
payload. Install the handler on old workers too, since a hook can expire before
the new release is available. Handler errors are reported without replacing the
original failure. Callback delivery is best effort and is not automatically
retried; queue a separate idempotent notification job if you need retries.

An expired wrapper retains its old deadline when retried with `queue:retry`.
Run `post-deploy-hook` again to start a fresh waiting window.

## Development

```bash
composer install
composer test
composer lint:check
```

Tests use Pest, Orchestra Testbench, and an in-memory SQLite queue. CI covers
Laravel 12 and 13 with their supported PHP versions.

## Credits and license

Created by [Mathias Grimm](https://github.com/mathiasgrimm). Package presentation
and artwork are adapted from [Laravel Puff](https://github.com/mathiasgrimm/laravel-puff).
Released under the [MIT license](LICENSE.md).
