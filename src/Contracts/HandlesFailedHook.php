<?php

namespace MathiasGrimm\PostDeployHook\Contracts;

use MathiasGrimm\PostDeployHook\Jobs\PostDeployHook;
use Throwable;

interface HandlesFailedHook
{
    public function handle(PostDeployHook $hook, ?Throwable $exception): void;
}
