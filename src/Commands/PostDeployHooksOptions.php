<?php

namespace MathiasGrimm\PostDeployHooks\Commands;

final readonly class PostDeployHooksOptions
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
