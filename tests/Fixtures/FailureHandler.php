<?php

namespace MathiasGrimm\PostDeployHook\Tests\Fixtures;

use MathiasGrimm\PostDeployHook\Contracts\HandlesFailedHook;
use MathiasGrimm\PostDeployHook\Jobs\PostDeployHook;
use Throwable;

class FailureHandler implements HandlesFailedHook
{
    public array $calls = [];

    public function handle(PostDeployHook $hook, ?Throwable $exception): void
    {
        $this->calls[] = [$hook, $exception];
    }
}
