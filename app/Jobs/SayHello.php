<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SayHello implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $name,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Pretend this is slow work (calling an API, making a PDF, ...)
        sleep(3);

        Log::info("Hello, {$this->name}!");
    }
}
