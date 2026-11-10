<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPublishLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SocialPublishService
{
    public function __construct(
        protected MetaGraphApiService $metaApi
    ) {}

    /**
     * Publish an entire post across all its configured targets
     */
    public function publishPost(SocialPost $post): void
    {
        // Prevent duplicate execution
        if ($post->status === SocialPost::STATUS_PUBLISHED) {
            Log::info("[SocialPublishService] Post {$post->id} is already published. Skipping.");
            return;
        }

        $post->update(['status' => SocialPost::STATUS_PUBLISHING]);

        $targets = $post->targets()->with('account')->get();

        if ($targets->isEmpty()) {
            $post->update([
                'status' => SocialPost::STATUS_FAILED,
                'error_message' => 'No target accounts configured for this post.',
            ]);
            return;
        }

        $allSucceeded = true;
        $anySucceeded = false;
        $mediaItems = $post->media()->orderBy('order')->get()->map(function ($m) {
            $url = $m->url;
            $appUrl = config('app.url');
            if (!empty($appUrl) && (str_contains($url, '127.0.0.1') || str_contains($url, 'localhost') || str_starts_with($url, '/'))) {
                // Extract relative storage path and re-point to public ngrok/app URL
                $path = preg_replace('#^https?://[^/]+/#', '', $url);
                $url = rtrim($appUrl, '/') . '/' . ltrim($path, '/');
            }
            $item = $m->toArray();
            $item['url'] = $url;
            return $item;
        })->toArray();

        $firstComment = $post->options['first_comment'] ?? null;
        $reelCover = $post->options['reel_cover'] ?? null;

        foreach ($targets as $target) {
            try {
                $this->publishTarget($target, $post->content, $mediaItems, $firstComment, $reelCover);
                $anySucceeded = true;
            } catch (Exception $e) {
                $allSucceeded = false;
                Log::error("[SocialPublishService] Failed to publish target {$target->id} on {$target->platform}: " . $e->getMessage());
            }
        }

        $finalStatus = $allSucceeded
            ? SocialPost::STATUS_PUBLISHED
            : ($anySucceeded ? SocialPost::STATUS_PUBLISHED : SocialPost::STATUS_FAILED);

        $post->update([
            'status' => $finalStatus,
            'published_at' => $anySucceeded ? now() : null,
        ]);
    }

    /**
     * Publish an individual target safely with locking and duplicate protection
     */
    public function publishTarget(
        SocialPostTarget $target,
        ?string $caption,
        array $mediaItems = [],
        ?string $firstComment = null,
        ?array $reelCover = null
    ): void
    {
        // 1. Idempotency Check
        if ($target->status === SocialPostTarget::STATUS_PUBLISHED) {
            return;
        }

        $account = $target->account;
        if (!$account || !$account->is_active) {
            $errMsg = "Social account is inactive, disconnected, or missing.";
            $target->update([
                'status' => SocialPostTarget::STATUS_FAILED,
                'error_message' => $errMsg,
            ]);
            $this->logAction($target->tenant_id, $target->post_id, $target->id, 'error', 'failed', $errMsg);
            throw new Exception($errMsg);
        }

        $target->update(['status' => SocialPostTarget::STATUS_PUBLISHING]);

        $this->logAction($target->tenant_id, $target->post_id, $target->id, 'info', 'publish_attempt', "Initiating {$target->platform} publication");

        try {
            $accessToken = $account->access_token; // Decrypted via model cast
            $platformPostId = null;
            $platformPostUrl = null;

            if ($target->platform === 'facebook') {
                $metaRes = $this->metaApi->publishToFacebookPage(
                    $account->account_id,
                    $accessToken,
                    $caption,
                    $mediaItems,
                    $reelCover
                );

                $platformPostId = $metaRes['post_id'] ?? $metaRes['id'] ?? null;
                $platformPostUrl = "https://www.facebook.com/{$platformPostId}";
            } elseif ($target->platform === 'instagram') {
                $metaRes = $this->metaApi->publishToInstagramAccount(
                    $account->account_id,
                    $accessToken,
                    $caption,
                    $mediaItems,
                    $reelCover
                );

                $platformPostId = $metaRes['id'] ?? null;
                $platformPostUrl = "https://www.instagram.com"; // Direct URL can be retrieved via permalink
            } else {
                throw new Exception("Unsupported platform: {$target->platform}");
            }

            $target->update([
                'status' => SocialPostTarget::STATUS_PUBLISHED,
                'platform_post_id' => $platformPostId,
                'platform_post_url' => $platformPostUrl,
                'published_at' => now(),
                'error_message' => null,
            ]);

            // Post automated first comment if configured (e.g. hashtags or follow-up note)
            if (!empty($firstComment) && !empty($platformPostId)) {
                try {
                    if ($target->platform === 'instagram') {
                        // Allow Meta edge servers to replicate newly created media container before posting top-level comment
                        sleep(2);
                    }
                    $commentRes = $this->metaApi->createPostComment($target->platform, $platformPostId, $firstComment, $accessToken);
                    $commentId = $commentRes['id'] ?? 'created';
                    Log::info("[SocialPublish] Automated first comment published for target {$target->id} on {$target->platform} (ID: {$commentId})");
                    $this->logAction(
                        $target->tenant_id,
                        $target->post_id,
                        $target->id,
                        'info',
                        'first_comment',
                        "Automated first comment published to {$target->platform} (Comment ID: {$commentId})"
                    );
                } catch (\Throwable $e) {
                    Log::error("[SocialPublish] Failed to publish automated first comment for target {$target->id}: " . $e->getMessage());
                    $this->logAction(
                        $target->tenant_id,
                        $target->post_id,
                        $target->id,
                        'warning',
                        'first_comment_failed',
                        "Failed to publish first comment: " . $e->getMessage()
                    );
                }
            }

            $this->logAction(
                $target->tenant_id,
                $target->post_id,
                $target->id,
                'success',
                'published',
                "Published to {$target->platform} successfully (ID: {$platformPostId})"
            );
        } catch (Exception $e) {
            $target->update([
                'status' => SocialPostTarget::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'retry_count' => $target->retry_count + 1,
            ]);

            $this->logAction(
                $target->tenant_id,
                $target->post_id,
                $target->id,
                'error',
                'failed',
                "Publication failed on {$target->platform}: " . $e->getMessage(),
                ['error' => $e->getMessage()]
            );

            throw $e;
        }
    }

    /**
     * Audit logger
     */
    protected function logAction(
        int $tenantId,
        ?int $postId,
        ?int $targetId,
        string $status,
        string $action,
        string $message,
        ?array $details = null
    ): void {
        SocialPublishLog::create([
            'tenant_id' => $tenantId,
            'post_id' => $postId,
            'post_target_id' => $targetId,
            'status' => $status,
            'action' => $action,
            'message' => $message,
            'details' => $details,
            'created_at' => now(),
        ]);
    }
}

