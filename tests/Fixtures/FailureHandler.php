<?php

namespace MathiasGrimm\PostDeployHooks\Tests\Fixtures;

use MathiasGrimm\PostDeployHooks\Contracts\HandlesFailedHooks;
use MathiasGrimm\PostDeployHooks\Jobs\PostDeployHooks;
use Throwable;

class FailureHandler implements HandlesFailedHooks
{
    public array $calls = [];

    public function handle(PostDeployHooks $hook, ?Throwable $exception): void
    {
        $this->calls[] = [$hook, $exception];
    }
}
