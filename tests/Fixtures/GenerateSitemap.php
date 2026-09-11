<?php

namespace MathiasGrimm\PostDeployHook\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class GenerateSitemap implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('sitemaps');
    }

    public function handle(): void {}
}
