<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialAnalytic;
use App\Models\SocialComment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SocialAnalyticsService
{
    public function __construct(
        protected MetaGraphApiService $metaApi
    ) {}

    /**
     * Sync live engagement metrics (likes, comments count, permalinks) from Meta Graph API
     */
    public function syncLiveMetrics(int $tenantId): array
    {
        $targets = SocialPostTarget::forTenant($tenantId)
            ->where('status', SocialPostTarget::STATUS_PUBLISHED)
            ->whereNotNull('platform_post_id')
            ->with('account')
            ->get();

        $syncedCount = 0;

        foreach ($targets as $target) {
            $account = $target->account;
            if (!$account || empty($account->access_token)) {
                continue;
            }

            try {
                $live = $this->metaApi->getPostMetrics(
                    $target->platform,
                    $target->platform_post_id,
                    $account->access_token,
                    $account->account_id
                );

                // Local verified comments count
                $localComments = SocialComment::where('social_post_target_id', $target->id)
                    ->orWhere('post_id', $target->platform_post_id)
                    ->count();

                $metrics = [
                    'likes' => (int) ($live['likes'] ?? 0),
                    'comments' => max((int) ($live['comments'] ?? 0), $localComments),
                    'shares' => (int) ($live['shares'] ?? 0),
                    'reach' => $live['reach'] ?? null,
                    'impressions' => $live['impressions'] ?? null,
                    'synced_at' => now()->toIso8601String(),
                ];

                $updateData = ['metrics' => $metrics];
                if (!empty($live['permalink']) && (empty($target->platform_post_url) || $target->platform_post_url === 'https://www.instagram.com')) {
                    $updateData['platform_post_url'] = $live['permalink'];
                }

                $target->update($updateData);
                $syncedCount++;
            } catch (\Throwable $e) {
                Log::warning("[SocialAnalytics] Failed to sync live metrics for target {$target->id}: " . $e->getMessage());
            }
        }

        return ['synced_targets' => $syncedCount];
    }

    /**
     * Get aggregate analytics summary, platform breakdown, and top posts
     */
    public function getAnalytics(int $tenantId, array $filters = []): array
    {
        // 1. Resolve Date Range
        $period = $filters['period'] ?? '30d';
        $now = Carbon::now();

        if ($period === '7d') {
            $startDate = $now->copy()->subDays(7)->startOfDay();
            $endDate = $now->copy()->endOfDay();
        } elseif ($period === '90d') {
            $startDate = $now->copy()->subDays(90)->startOfDay();
            $endDate = $now->copy()->endOfDay();
        } elseif ($period === 'custom' && !empty($filters['start_date']) && !empty($filters['end_date'])) {
            $startDate = Carbon::parse($filters['start_date'])->startOfDay();
            $endDate = Carbon::parse($filters['end_date'])->endOfDay();
        } else {
            // Default 30d
            $startDate = $now->copy()->subDays(30)->startOfDay();
            $endDate = $now->copy()->endOfDay();
        }

        // 2. Automatically sync live metrics if any published target has empty metrics or stale metrics
        $staleThreshold = now()->subMinutes(10)->toIso8601String();
        $needsSync = SocialPostTarget::forTenant($tenantId)
            ->where('status', SocialPostTarget::STATUS_PUBLISHED)
            ->whereNotNull('platform_post_id')
            ->where(function ($q) use ($staleThreshold) {
                $q->whereNull('metrics')
                  ->orWhereNull('metrics->reach')
                  ->orWhereNull('metrics->impressions')
                  ->orWhere('metrics->synced_at', '<', $staleThreshold);
            })
            ->exists();

        if ($needsSync) {
            $this->syncLiveMetrics($tenantId);
        }

        // 3. Query Published Targets within Date Range
        $targetsQuery = SocialPostTarget::forTenant($tenantId)
            ->where('status', SocialPostTarget::STATUS_PUBLISHED)
            ->whereBetween('published_at', [$startDate, $endDate]);

        if (!empty($filters['platform'])) {
            $targetsQuery->where('platform', $filters['platform']);
        }
        if (!empty($filters['account_id'])) {
            $targetsQuery->where('social_account_id', $filters['account_id']);
        }

        $targets = $targetsQuery->with(['post.media', 'account:id,platform,name,profile_picture_url'])->get();

        // 4. Compute per-target and platform-specific metrics
        $facebookTargets = $targets->where('platform', 'facebook');
        $instagramTargets = $targets->where('platform', 'instagram');

        $fbLikes = 0;
        $fbComments = 0;
        $fbShares = 0;
        $igLikes = 0;
        $igComments = 0;
        $igShares = 0;

        foreach ($facebookTargets as $t) {
            $localComments = SocialComment::where('social_post_target_id', $t->id)
                ->orWhere('post_id', $t->platform_post_id)
                ->count();
            $fbLikes += (int) ($t->metrics['likes'] ?? 0);
            $fbComments += max((int) ($t->metrics['comments'] ?? 0), $localComments);
            $fbShares += (int) ($t->metrics['shares'] ?? 0);
        }

        foreach ($instagramTargets as $t) {
            $localComments = SocialComment::where('social_post_target_id', $t->id)
                ->orWhere('post_id', $t->platform_post_id)
                ->count();
            $igLikes += (int) ($t->metrics['likes'] ?? 0);
            $igComments += max((int) ($t->metrics['comments'] ?? 0), $localComments);
            $igShares += (int) ($t->metrics['shares'] ?? 0);
        }

        $totalLikes = $fbLikes + $igLikes;
        $totalComments = $fbComments + $igComments;
        $totalShares = $fbShares + $igShares;
        $totalEngagement = $totalLikes + $totalComments + $totalShares;

        // Tally reach & impressions if returned by Meta
        $sumReach = 0;
        $hasReach = false;
        $sumImpressions = 0;
        $hasImpressions = false;

        foreach ($targets as $t) {
            if (isset($t->metrics['reach']) && $t->metrics['reach'] !== null) {
                $hasReach = true;
                $sumReach += (int) $t->metrics['reach'];
            }
            if (isset($t->metrics['impressions']) && $t->metrics['impressions'] !== null) {
                $hasImpressions = true;
                $sumImpressions += (int) $t->metrics['impressions'];
            }
        }

        if ($targets->isEmpty()) {
            $totalReach = 0;
            $totalImpressions = 0;
        } else {
            $totalReach = $hasReach ? $sumReach : null;
            $totalImpressions = $hasImpressions ? $sumImpressions : null;
        }

        // 5. Build and rank Top Performing Posts by engagement
        $topPosts = $targets->map(function ($target) {
            $post = $target->post;
            $media = $post?->media->first();

            $localComments = SocialComment::where('social_post_target_id', $target->id)
                ->orWhere('post_id', $target->platform_post_id)
                ->count();

            $likes = (int) ($target->metrics['likes'] ?? 0);
            $comments = max((int) ($target->metrics['comments'] ?? 0), $localComments);
            $shares = (int) ($target->metrics['shares'] ?? 0);
            $engagement = $likes + $comments + $shares;

            return [
                'target_id' => $target->id,
                'post_id' => $post?->id,
                'platform' => $target->platform,
                'account_name' => $target->account?->name ?? 'Social Account',
                'account_avatar' => $target->account?->profile_picture_url,
                'caption' => $post?->content ?? '',
                'media_url' => $media?->url,
                'media_type' => $media?->media_type ?? 'text',
                'published_at' => $target->published_at?->toIso8601String(),
                'platform_post_url' => $target->platform_post_url,
                'likes' => $likes,
                'comments' => $comments,
                'shares' => $shares,
                'reach' => $target->metrics['reach'] ?? null,
                'impressions' => $target->metrics['impressions'] ?? null,
                'engagement' => $engagement,
            ];
        })->sortByDesc('engagement')->take(10)->values();

        // 6. Connected Accounts summary
        $accounts = SocialAccount::forTenant($tenantId)->active()->get();
        $activeAccountsCount = $accounts->count();
        $totalFollowers = $accounts->sum('followers_count');

        return [
            'period' => [
                'type' => $period,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
            'kpis' => [
                'total_posts' => $targets->count(),
                'active_accounts' => $activeAccountsCount,
                'total_followers' => $totalFollowers,
                'total_reach' => $totalReach,
                'total_impressions' => $totalImpressions,
                'total_likes' => $totalLikes,
                'total_comments' => $totalComments,
                'total_shares' => $totalShares,
                'total_engagement' => $totalEngagement,
            ],
            'platforms' => [
                'facebook' => [
                    'posts_count' => $facebookTargets->count(),
                    'accounts_count' => $accounts->where('platform', 'facebook')->count(),
                    'likes' => $fbLikes,
                    'comments' => $fbComments,
                    'shares' => $fbShares,
                ],
                'instagram' => [
                    'posts_count' => $instagramTargets->count(),
                    'accounts_count' => $accounts->where('platform', 'instagram')->count(),
                    'likes' => $igLikes,
                    'comments' => $igComments,
                    'shares' => $igShares,
                ],
            ],
            'top_posts' => $topPosts,
        ];
    }
}


