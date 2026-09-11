<?php

return [
    'version' => env('LARAVEL_POST_DEPLOY_HOOK_VERSION'),

    'expires' => 30,

    'backoff' => 60,

    // A class implementing Contracts\HandlesFailedHook, resolved from the container.
    'on_failure' => null,
];
