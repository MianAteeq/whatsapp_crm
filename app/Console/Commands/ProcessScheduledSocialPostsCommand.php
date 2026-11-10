<?php

namespace App\Console\Commands;

use App\Models\SocialPost;
use App\Jobs\PublishSocialPostJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessScheduledSocialPostsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'social:publish-scheduled';

    /**
     * The console command description.
     */
    protected $description = 'Dispatch publishing jobs for all scheduled social media posts that are due';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $duePosts = SocialPost::scheduledDue()->get();

        if ($duePosts->isEmpty()) {
            return 0;
        }

        $this->info("Found {$duePosts->count()} scheduled social posts ready for publishing.");

        foreach ($duePosts as $post) {
            $this->line("Dispatching publication for Post #{$post->id} (Tenant: {$post->tenant_id})...");

            // Set to publishing immediately to prevent double-firing in rapid intervals
            $post->update(['status' => SocialPost::STATUS_PUBLISHING]);

            PublishSocialPostJob::dispatch($post);
        }

        $this->info("All due social posts dispatched to publishing queue.");
        return 0;
    }
}

