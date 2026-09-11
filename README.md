<p align="center">
    <img src="art/banner.svg" alt="Laravel Post Deploy Hooks" width="100%">
</p>

# Laravel Post Deploy Hooks

Run a queued job once a worker has loaded your new release. Useful for tasks
such as generating a sitemap or refreshing cached data after deployment.

Requires PHP 8.2+ with Laravel 12, or PHP 8.3+ with Laravel 13.

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

Each worker must keep the version of the code it is running. Restart workers
when deploying new code.

**2. Queue your job during deployment.**

Use the same version and a job that implements Laravel's `ShouldQueue`:

```bash
php artisan post-deploy-hooks \
  --deploy-version=release-123 \
  --job='App\Jobs\GenerateSitemap'
```

The hook checks the worker's version. If it matches, the hook sends your job to
the queue. Otherwise, it waits 60 seconds before trying again, for up to 30 minutes.

Using Laravel Cloud? Use `$LARAVEL_CLOUD_COMMIT_SHA` as the version.
See the [Cloud setup example](docs/usage.md#set-the-release-version).

## Options

| Option | Purpose |
| --- | --- |
| `--deploy-version` | The version to wait for. Required. |
| `--job` | The job class to send to the queue. Required. |
| `--expires=60` | How many minutes the hook can wait. |
| `--with='siteId=123'` | A constructor argument. Repeat for more arguments. Values are strings. |
| `--connection=redis` | Queue connection for the hook. |
| `--queue=deployments` | Queue name for the hook. |

To change the defaults or add a failure handler, publish the config:

```bash
php artisan vendor:publish --tag=post-deploy-hooks-config
```

Each setting is explained in [config/post-deploy-hooks.php](config/post-deploy-hooks.php).
Command options override the configured defaults.

## Things to know

- The hook checks the worker's version. It does not check whether the whole
  deployment is finished or the website is healthy.
- Your job runs separately and may be picked up by another worker. It keeps its
  own queue settings, retries, and failure handling.
- Make your job safe to run more than once. Running the command twice or retrying
  queue work can send it more than once.
- The waiting limit never resets. An expired hook is marked as failed when a
  worker next processes it.
- `sync` cannot wait and retry. See the [queue requirements](docs/usage.md#requirements)
  for other drivers and older database queues.

The [usage guide](docs/usage.md) covers job arguments, running hooks from PHP, failure
handlers, testing, and releases.

## Credits and license

Created by [Mathias Grimm](https://github.com/mathiasgrimm). Artwork adapted from
[Laravel Puff](https://github.com/mathiasgrimm/laravel-puff). [MIT license](LICENSE.md).

An independent community package, not affiliated with or endorsed by Laravel or
Laravel Cloud. Laravel is a trademark of its respective owner.
