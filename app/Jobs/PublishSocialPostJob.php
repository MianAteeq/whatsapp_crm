<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Services\Social\SocialPublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishSocialPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [30, 120, 300];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public SocialPost $post
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SocialPublishService $publishService): void
    {
        Log::info("[PublishSocialPostJob] Processing publication for Post ID: {$this->post->id}");
        $publishService->publishPost($this->post);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("[PublishSocialPostJob] Final failure for Post ID {$this->post->id}: " . ($exception ? $exception->getMessage() : 'Unknown error'));

        $this->post->update([
            'status' => SocialPost::STATUS_FAILED,
            'error_message' => $exception ? $exception->getMessage() : 'Background publication failed after 3 attempts.',
        ]);
    }
}

