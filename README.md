<p align="center">
    <img src="art/banner.svg" alt="Laravel Post Deploy Hooks" width="100%">
</p>

# Laravel Post Deploy Hooks

Run a queued job once a worker has loaded your new release. Useful for tasks
such as generating a sitemap or refreshing cached data after deployment.

Requires PHP 8.3+ and Laravel 12+.

## Install

```bash
composer require mathiasgrimm/laravel-post-deploy-hooks
```

Use a queue that supports delayed retries, such as Redis or the database queue.

## Quick start

**1. Set the version for each release.**

Set this environment variable before caching your config:

```dotenv
POST_DEPLOY_HOOKS_VERSION=release-123
```

**2. Queue your job during deployment.**

Use the same version and a job that implements Laravel's `ShouldQueue`:

```bash
php artisan post-deploy-hooks \
  --deploy-version=release-123 \
  --job='App\Jobs\GenerateSitemap'
```

The hook checks the worker's version. If it matches, the hook sends your job to
the queue. Otherwise, it waits 60 seconds before trying again, for up to 30 minutes.

On Laravel Cloud, use `$LARAVEL_CLOUD_COMMIT_SHA` as the version. In your build
commands, write it to the release's `.env` before caching config:

```bash
test -n "$LARAVEL_CLOUD_COMMIT_SHA" || exit 1
echo "POST_DEPLOY_HOOKS_VERSION=$LARAVEL_CLOUD_COMMIT_SHA" >> .env
php artisan config:cache
```

If the key already exists, replace its value instead of adding another line.
Keep this setting separate for each release, and ensure an existing environment
variable does not override it. In your deploy command, pass
`--deploy-version="$LARAVEL_CLOUD_COMMIT_SHA"`.

## Options

| Option | Purpose |
| --- | --- |
| `--deploy-version` | The version to wait for. Required. |
| `--job` | The job class to send to the queue. Required. |
| `--expires=60` | How many minutes the hook can wait. |
| `--with='siteId=123'` | A constructor argument. Repeat for more arguments. Values are strings. |
| `--connection=redis` | Queue connection for the hook. |
| `--queue=deployments` | Queue name for the hook. |

Argument names must match your job's constructor. For example, use
`--with='siteId=123' --with='locale=en'` for `__construct(string $siteId, string $locale)`.
Numbers and booleans are passed as strings too. Missing required arguments,
unknown names, and duplicate names are rejected. Your job class is checked only
on a worker running the matching version, so it can be new to that release.

## Configuration

To change the defaults or add a failure handler, publish the config:

```bash
php artisan vendor:publish --tag=post-deploy-hooks-config
```

In `config/post-deploy-hooks.php`:

```php
'job' => [
    'connection' => null, // Laravel's default connection.
    'queue' => null,      // The connection's default queue.
    'expire' => 30,       // Minutes to wait.
    'backoff' => 60,      // Seconds between attempts.
    'failure_handler' => null,
],
```

Command options override these defaults. `expire` and `backoff` must be positive
whole numbers. Queue settings apply to the hook; your job keeps its own settings.

## Run from PHP

```php
use MathiasGrimm\PostDeployHooks\Jobs\PostDeployHooks;

PostDeployHooks::dispatch(
    version: $release,
    job: \App\Jobs\GenerateSitemap::class,
    expires: 60,
    arguments: ['siteId' => '123', 'locale' => 'en'],
);
```

Leave out `expires` to use the configured limit. Use `->onConnection()` and
`->onQueue()` to override routing. PHP arguments keep the types you supply.

## Handle failures

Set `job.failure_handler` to `App\Actions\ReportFailedHook::class` and create:

```php
namespace App\Actions;

use MathiasGrimm\PostDeployHooks\Contracts\HandlesFailedHooks;
use MathiasGrimm\PostDeployHooks\Jobs\PostDeployHooks;
use Throwable;

class ReportFailedHook implements HandlesFailedHooks
{
    public function handle(PostDeployHooks $hook, ?Throwable $exception): void
    {
        logger()->error('Post-deploy hook failed', [
            'version' => $hook->version,
            'job' => $hook->jobClass,
            'exception' => $exception,
        ]);
    }
}
```

This handles hooks that expire or fail to send your job. Failures inside your
job belong in its own `failed()` method. Errors in the handler are reported but
not retried. Run the command again to start a new waiting period for an expired hook.

## Things to know

- The hook checks the worker's version. It does not check whether the whole
  deployment is finished or the website is healthy.
- Your job runs separately and may be picked up by another worker. It keeps its
  own queue settings, retries, and failure handling.
- Make your job safe to run more than once. Running the command twice or retrying
  queue work can send it more than once.
- The waiting limit never resets. An expired hook is marked as failed when a
  worker next processes it.
- Use the same queue system for the deploy command and workers. Any configured
  connection is accepted, but waiting needs delayed retries. `sync` runs once
  immediately; a version mismatch is not retried and cannot later expire.
  `deferred` and `background` cannot wait reliably either; `null` discards the hook.
- Older MySQL queue tables may allow only 255 attempts in `jobs.attempts`.
  Increase that column's capacity if your waiting limit and retry delay need more.
- Redeploying the same commit uses the same version. Use a unique release ID if
  you need to distinguish those deployments.

## Development and releases

```bash
composer install
make test
```

`make test` runs Pint and Pest. To publish a new version, run
`make release VERSION=v0.1.1` from a clean `main` branch matching `origin/main`.
This runs the checks and creates a GitHub release. The GitHub CLI must be signed in.

## Credits and license

Created by [Mathias Grimm](https://github.com/mathiasgrimm). Artwork adapted from
[Laravel Puff](https://github.com/mathiasgrimm/laravel-puff). [MIT license](LICENSE.md).

An independent community package, not affiliated with or endorsed by Laravel or
Laravel Cloud. Laravel is a trademark of its respective owner.
