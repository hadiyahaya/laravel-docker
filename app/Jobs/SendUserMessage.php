<?php

namespace App\Jobs;

use App\Mail\UserMessage;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendUserMessage implements ShouldQueue
{
    use Queueable;

    /**
     * How many times to try the job before it is marked as failed.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before trying again.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public User $user,
        public string $subjectLine,
        public string $body,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Mail::to($this->user)->send(new UserMessage($this->user, $this->subjectLine, $this->body));
    }

    /**
     * Called once all tries have failed.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("Could not email {$this->user->email}: {$exception?->getMessage()}");
    }
}
