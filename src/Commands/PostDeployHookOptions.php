<?php

namespace MathiasGrimm\PostDeployHook\Commands;

final readonly class PostDeployHookOptions
{
    /**
     * @param  array<string, string>  $arguments
     */
    public function __construct(
        public string $version,
        public string $job,
        public int $expires,
        public array $arguments,
        public ?string $connection,
        public ?string $queue,
    ) {}
}
