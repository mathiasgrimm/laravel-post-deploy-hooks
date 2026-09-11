<?php

return [
    'version' => env('POST_DEPLOY_HOOKS_VERSION'),

    'job' => [
        'expire' => 30,
        'backoff' => 60,

        // A class implementing Contracts\HandlesFailedHooks, resolved from the container.
        'failure_handler' => null,
    ],
];
