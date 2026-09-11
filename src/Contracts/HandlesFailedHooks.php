<?php

namespace MathiasGrimm\PostDeployHooks\Contracts;

use MathiasGrimm\PostDeployHooks\Jobs\PostDeployHooks;
use Throwable;

interface HandlesFailedHooks
{
    public function handle(PostDeployHooks $hook, ?Throwable $exception): void;
}
