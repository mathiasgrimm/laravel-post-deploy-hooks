<?php

return [
    'version' => env('POST_DEPLOY_HOOK_VERSION'),

    'job' => [
        'expire' => 30,
        'backoff' => 60,
    ],

    // A class implementing Contracts\HandlesFailedHook, resolved from the container.
    'on_failure' => null,
];
