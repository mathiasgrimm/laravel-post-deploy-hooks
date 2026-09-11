<?php

return [
    // The version running on this worker. It must match --deploy-version
    // before the hook can send your job to the queue.
    'version' => env('POST_DEPLOY_HOOKS_VERSION'),

    // Settings for waiting for the right version and handling hook failures.
    'job' => [
        // How many minutes the hook can wait before it expires.
        // Use --expires to choose a different limit for a single hook.
        'expire' => 30,

        // How many seconds to wait before checking the version again.
        'backoff' => 60,

        // Optional class to call when the hook expires or fails to send your job.
        // It must implement MathiasGrimm\PostDeployHooks\Contracts\HandlesFailedHooks.
        // Leave null to skip this. Your job handles its own failures separately.
        'failure_handler' => null,
    ],
];
