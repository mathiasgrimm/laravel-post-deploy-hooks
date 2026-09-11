<?php

use MathiasGrimm\PostDeployHooks\Jobs\PostDeployHooks;

return [
    /*
    |--------------------------------------------------------------------------
    | Deployment Version
    |--------------------------------------------------------------------------
    |
    | The version running on this worker. It must match --deploy-version
    | before the hook can send your job to the queue.
    |
    */

    'version' => env('POST_DEPLOY_HOOKS_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Job Settings
    |--------------------------------------------------------------------------
    |
    | Settings for waiting for the right version and handling hook failures.
    |
    */

    'job' => [
        /*
        |--------------------------------------------------------------------------
        | Job Class
        |--------------------------------------------------------------------------
        |
        | The hook job that waits for the right version before sending your job.
        | To customize it, use a class that extends PostDeployHooks and keeps
        | the same constructor arguments.
        |
        */

        'class' => PostDeployHooks::class,

        /*
        |--------------------------------------------------------------------------
        | Queue Connection
        |--------------------------------------------------------------------------
        |
        | The queue connection used to send the hook. Leave null to use Laravel's
        | default connection. Use --connection to override it for a single hook.
        | This does not change your application's job connection.
        |
        */

        'connection' => null,

        /*
        |--------------------------------------------------------------------------
        | Queue Name
        |--------------------------------------------------------------------------
        |
        | The queue where the hook waits. Leave null to use the connection's
        | default queue. Use --queue to override it for a single hook.
        | This does not change your application's job queue.
        |
        */

        'queue' => null,

        /*
        |--------------------------------------------------------------------------
        | Expiry (Minutes)
        |--------------------------------------------------------------------------
        |
        | How many minutes the hook can wait before it expires.
        | Use --expires to choose a different limit for a single hook.
        |
        */

        'expire' => 30,

        /*
        |--------------------------------------------------------------------------
        | Retry Delay (Seconds)
        |--------------------------------------------------------------------------
        |
        | How many seconds to wait before checking the version again.
        |
        */

        'backoff' => 60,

        /*
        |--------------------------------------------------------------------------
        | Failure Handler
        |--------------------------------------------------------------------------
        |
        | Optional class to call when the hook expires or fails to send your job.
        | It must implement MathiasGrimm\PostDeployHooks\Contracts\HandlesFailedHooks.
        | Leave null to skip this. Your job handles its own failures separately.
        |
        */

        'failure_handler' => null,
    ],
];
