<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class MetaGraphApiService
{
    protected string $graphVersion;
    protected string $appId;
    protected string $appSecret;
    protected string $baseUrl;

    public function __construct()
    {
        $this->graphVersion = config('services.facebook.graph_version', 'v21.0');
        $this->appId = (string) config('services.facebook.app_id', env('FACEBOOK_APP_ID', ''));
        $this->appSecret = (string) config('services.facebook.app_secret', env('FACEBOOK_APP_SECRET', ''));
        $this->baseUrl = "https://graph.facebook.com/{$this->graphVersion}";
    }

    /**
     * Build the Meta OAuth Authorization URL for Facebook Login for Business (Pages & Instagram)
     */
    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        $configId = (string) config('services.facebook.config_id', env('FACEBOOK_CONFIG_ID', '1908588223842822'));

        $queryParams = [
            'client_id' => $this->appId,
            'redirect_uri' => $redirectUri,
            'config_id' => $configId,
            'response_type' => 'code',
            'override_default_response_type' => 'true',
            'auth_type' => 'rerequest',
            'state' => $state,
        ];

        $queryString = http_build_query($queryParams);

        return "https://www.facebook.com/{$this->graphVersion}/dialog/oauth?{$queryString}";
    }

    /**
     * Exchange short-lived authorization code for a Long-Lived User Access Token
     */
    public function exchangeCodeForLongLivedToken(string $code, string $redirectUri): array
    {
        // 1. Get short-lived user token
        $shortLivedResponse = Http::timeout(20)->get("{$this->baseUrl}/oauth/access_token", [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if ($shortLivedResponse->failed()) {
            $error = $shortLivedResponse->json('error.message', $shortLivedResponse->body());
            Log::error("[MetaGraphApi] Failed to exchange code for token: {$error}");
            throw new Exception("Meta OAuth Error: {$error}");
        }

        $shortToken = $shortLivedResponse->json('access_token');

        // 2. Exchange for 60-day Long-Lived User Access Token
        $longLivedResponse = Http::timeout(20)->get("{$this->baseUrl}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'fb_exchange_token' => $shortToken,
        ]);

        if ($longLivedResponse->failed()) {
            // Fallback to short-lived token if exchange fails
            return [
                'access_token' => $shortToken,
                'expires_in' => $shortLivedResponse->json('expires_in', 5184000), // ~60 days default
            ];
        }

        return [
            'access_token' => $longLivedResponse->json('access_token'),
            'expires_in' => $longLivedResponse->json('expires_in', 5184000),
        ];
    }

    /**
     * Fetch all Facebook Pages owned/managed by the user and their connected Instagram Professional Accounts
     */
    public function getConnectedPagesAndInstagramAccounts(string $userAccessToken): array
    {
        $fields = implode(',', [
            'id',
            'name',
            'category',
            'access_token',
            'tasks',
            'picture.type(large)',
            'instagram_business_account{id,username,name,profile_picture_url,followers_count}',
        ]);

        $response = Http::withToken($userAccessToken)
            ->timeout(20)
            ->get("{$this->baseUrl}/me/accounts", [
                'fields' => $fields,
                'limit' => 100,
            ]);

        if ($response->failed()) {
            $error = $response->json('error.message', $response->body());
            Log::error("[MetaGraphApi] Failed to fetch accounts: {$error}");
            throw new Exception("Unable to retrieve Facebook Pages from Meta: {$error}");
        }

        return $response->json('data', []);
    }

    /**
     * Inspect access token validity and expiration
     */
    public function debugToken(string $inputToken): array
    {
        $appAccessToken = "{$this->appId}|{$this->appSecret}";

        $response = Http::timeout(15)->get("{$this->baseUrl}/debug_token", [
            'input_token' => $inputToken,
            'access_token' => $appAccessToken,
        ]);

        if ($response->failed()) {
            return ['is_valid' => false, 'error' => $response->json('error.message', 'Token inspection failed')];
        }

        return $response->json('data', ['is_valid' => false]);
    }

    /**
     * Publish post to Facebook Page
     */
    public function publishToFacebookPage(
        string $pageId,
        string $pageAccessToken,
        ?string $message,
        array $mediaItems = [],
        ?array $reelCover = null
    ): array {
        $count = count($mediaItems);

        // Case A: Text Only post
        if ($count === 0) {
            $response = Http::withToken($pageAccessToken)
                ->timeout(30)
                ->post("{$this->baseUrl}/{$pageId}/feed", [
                    'message' => $message ?? '',
                ]);

            return $this->handleResponse($response, 'Facebook Feed Post');
        }

        // Case B: Single Video post
        if ($count === 1 && $mediaItems[0]['media_type'] === 'video') {
            $videoUrl = $mediaItems[0]['url'];

            // Build request
            $request = Http::withToken($pageAccessToken)->timeout(120);

            // Check if Reel cover/custom thumbnail is provided
            if ($reelCover && !empty($reelCover['type'])) {
                $coverBytes = null;
                $coverFilename = 'cover.jpg';

                // 1. Try local storage file path
                if (!empty($reelCover['custom_cover_path']) && Storage::disk('public')->exists($reelCover['custom_cover_path'])) {
                    $coverBytes = Storage::disk('public')->get($reelCover['custom_cover_path']);
                    $coverFilename = basename($reelCover['custom_cover_path']);
                } elseif (!empty($reelCover['custom_cover_url'])) {
                    // 2. Try parsing relative storage path from custom_cover_url
                    try {
                        $parsedPath = parse_url($reelCover['custom_cover_url'], PHP_URL_PATH);
                        if ($parsedPath && str_contains($parsedPath, '/storage/')) {
                            $relPath = substr($parsedPath, strpos($parsedPath, '/storage/') + 9);
                            if (Storage::disk('public')->exists($relPath)) {
                                $coverBytes = Storage::disk('public')->get($relPath);
                                $coverFilename = basename($relPath);
                            }
                        }
                        if (!$coverBytes) {
                            $coverRes = Http::timeout(15)->get($reelCover['custom_cover_url']);
                            if ($coverRes->successful()) {
                                $coverBytes = $coverRes->body();
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning("[MetaGraphApi] Failed to resolve Facebook video cover bytes: " . $e->getMessage());
                    }
                }

                if ($coverBytes) {
                    Log::info("[MetaGraphApi] Attaching custom thumbnail 'thumb' for Facebook Page video: {$coverFilename}");
                    $request->attach('thumb', $coverBytes, $coverFilename);
                }
            }

            $response = $request->post("{$this->baseUrl}/{$pageId}/videos", [
                'file_url' => $videoUrl,
                'description' => $message ?? '',
            ]);

            $resData = $this->handleResponse($response, 'Facebook Video Post');
            $videoId = $resData['id'] ?? null;

            // Set preferred thumbnail edge on Facebook if cover bytes are available
            if ($videoId && !empty($coverBytes)) {
                try {
                    $thumbRes = Http::withToken($pageAccessToken)
                        ->timeout(30)
                        ->attach('source', $coverBytes, $coverFilename)
                        ->post("{$this->baseUrl}/{$videoId}/thumbnails", [
                            'is_preferred' => 'true',
                        ]);
                    Log::info("[MetaGraphApi] Preferred thumbnail set on Facebook video {$videoId}: " . $thumbRes->body());
                } catch (\Throwable $thumbEx) {
                    Log::warning("[MetaGraphApi] Setting preferred thumbnail on Facebook video {$videoId} warning: " . $thumbEx->getMessage());
                }
            }

            return $resData;
        }

        // Case C: Single Photo post
        if ($count === 1 && $mediaItems[0]['media_type'] === 'image') {
            $imageUrl = $mediaItems[0]['url'];
            $response = Http::withToken($pageAccessToken)
                ->timeout(30)
                ->post("{$this->baseUrl}/{$pageId}/photos", [
                    'url' => $imageUrl,
                    'message' => $message ?? '',
                ]);

            return $this->handleResponse($response, 'Facebook Photo Post');
        }

        // Case D: Multiple Photos (Carousel / Multi-image story)
        $attachedMedia = [];
        foreach ($mediaItems as $media) {
            if ($media['media_type'] === 'image') {
                $uploadRes = Http::withToken($pageAccessToken)
                    ->timeout(30)
                    ->post("{$this->baseUrl}/{$pageId}/photos", [
                        'url' => $media['url'],
                        'published' => false,
                    ]);

                if ($uploadRes->successful() && $uploadRes->json('id')) {
                    $attachedMedia[] = ['media_fbid' => $uploadRes->json('id')];
                }
            }
        }

        if (empty($attachedMedia)) {
            throw new Exception("Failed to upload photos for Facebook carousel post.");
        }

        $feedRes = Http::withToken($pageAccessToken)
            ->timeout(30)
            ->post("{$this->baseUrl}/{$pageId}/feed", [
                'message' => $message ?? '',
                'attached_media' => $attachedMedia,
            ]);

        return $this->handleResponse($feedRes, 'Facebook Multi-Photo Post');
    }

    /**
     * Publish post to Instagram Professional Account
     * Instagram Content Publishing requires container creation -> (status check) -> media_publish
     */
    public function publishToInstagramAccount(
        string $igUserId,
        string $accessToken,
        ?string $caption,
        array $mediaItems = [],
        ?array $reelCover = null
    ): array {
        $count = count($mediaItems);

        if ($count === 0) {
            throw new Exception("Instagram does not support text-only posts. Please attach at least one photo or video.");
        }

        // Single Image Post
        if ($count === 1 && $mediaItems[0]['media_type'] === 'image') {
            // 1. Create Media Container
            $containerRes = Http::withToken($accessToken)
                ->timeout(30)
                ->post("{$this->baseUrl}/{$igUserId}/media", [
                    'image_url' => $mediaItems[0]['url'],
                    'caption' => $caption ?? '',
                ]);

            $container = $this->handleResponse($containerRes, 'Instagram Image Container Creation');
            $creationId = $container['id'];

            // 2. Publish Container
            return $this->publishInstagramContainer($igUserId, $accessToken, $creationId);
        }

        // Single Video / Reel Post
        if ($count === 1 && $mediaItems[0]['media_type'] === 'video') {
            $containerParams = [
                'media_type' => 'REELS',
                'video_url' => $mediaItems[0]['url'],
                'caption' => $caption ?? '',
            ];

            // Apply Reel Cover if provided
            if (!empty($reelCover) && !empty($reelCover['type'])) {
                $coverType = $reelCover['type'];

                if ($coverType === 'custom' && !empty($reelCover['custom_cover_url'])) {
                    // Priority 1: User explicitly uploaded custom cover image
                    $coverUrl = $reelCover['custom_cover_url'];
                    $appUrl = config('app.url');
                    if (!empty($appUrl) && (str_contains($coverUrl, '127.0.0.1') || str_contains($coverUrl, 'localhost') || str_starts_with($coverUrl, '/'))) {
                        $path = preg_replace('#^https?://[^/]+/#', '', $coverUrl);
                        $coverUrl = rtrim($appUrl, '/') . '/' . ltrim($path, '/');
                    }
                    $containerParams['cover_url'] = $coverUrl;
                    Log::info("[MetaGraphApi] Applying custom cover_url for Instagram Reel: {$coverUrl}");
                } elseif ($coverType === 'frame' && isset($reelCover['thumb_offset_ms'])) {
                    // Priority 2: User scrubbed and selected video frame timestamp in milliseconds
                    $containerParams['thumb_offset'] = (int) $reelCover['thumb_offset_ms'];
                    Log::info("[MetaGraphApi] Applying thumb_offset ({$containerParams['thumb_offset']}ms) for Instagram Reel");
                } elseif (!empty($reelCover['custom_cover_url'])) {
                    // Fallback to custom cover URL
                    $coverUrl = $reelCover['custom_cover_url'];
                    $appUrl = config('app.url');
                    if (!empty($appUrl) && (str_contains($coverUrl, '127.0.0.1') || str_contains($coverUrl, 'localhost') || str_starts_with($coverUrl, '/'))) {
                        $path = preg_replace('#^https?://[^/]+/#', '', $coverUrl);
                        $coverUrl = rtrim($appUrl, '/') . '/' . ltrim($path, '/');
                    }
                    $containerParams['cover_url'] = $coverUrl;
                    Log::info("[MetaGraphApi] Fallback cover_url for Instagram Reel: {$coverUrl}");
                } elseif (isset($reelCover['thumb_offset_ms'])) {
                    $containerParams['thumb_offset'] = (int) $reelCover['thumb_offset_ms'];
                    Log::info("[MetaGraphApi] Fallback thumb_offset ({$containerParams['thumb_offset']}ms) for Instagram Reel");
                }
            }

            $containerRes = Http::withToken($accessToken)
                ->timeout(60)
                ->post("{$this->baseUrl}/{$igUserId}/media", $containerParams);

            $container = $this->handleResponse($containerRes, 'Instagram Video Container Creation');
            $creationId = $container['id'];

            // Poll until container is ready
            $this->waitForInstagramContainerReady($creationId, $accessToken);

            // Publish Container
            return $this->publishInstagramContainer($igUserId, $accessToken, $creationId);
        }

        // Carousel Post (Multiple Photos / Videos)
        $childrenIds = [];
        foreach ($mediaItems as $media) {
            $params = [
                'is_carousel_item' => true,
            ];
            if ($media['media_type'] === 'video') {
                $params['media_type'] = 'VIDEO';
                $params['video_url'] = $media['url'];
            } else {
                $params['image_url'] = $media['url'];
            }

            $childRes = Http::withToken($accessToken)
                ->timeout(30)
                ->post("{$this->baseUrl}/{$igUserId}/media", $params);

            if ($childRes->successful() && $childRes->json('id')) {
                $childId = $childRes->json('id');
                if ($media['media_type'] === 'video') {
                    $this->waitForInstagramContainerReady($childId, $accessToken);
                }
                $childrenIds[] = $childId;
            }
        }

        if (count($childrenIds) < 2) {
            throw new Exception("Instagram Carousel requires at least 2 successfully processed media items.");
        }

        // Create Carousel Container
        $carouselRes = Http::withToken($accessToken)
            ->timeout(30)
            ->post("{$this->baseUrl}/{$igUserId}/media", [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $childrenIds),
                'caption' => $caption ?? '',
            ]);

        $carouselContainer = $this->handleResponse($carouselRes, 'Instagram Carousel Container Creation');
        return $this->publishInstagramContainer($igUserId, $accessToken, $carouselContainer['id']);
    }

    /**
     * Helper to publish an Instagram container
     */
    protected function publishInstagramContainer(string $igUserId, string $accessToken, string $creationId): array
    {
        $publishRes = Http::withToken($accessToken)
            ->timeout(30)
            ->post("{$this->baseUrl}/{$igUserId}/media_publish", [
                'creation_id' => $creationId,
            ]);

        return $this->handleResponse($publishRes, 'Instagram Media Publish');
    }

    /**
     * Poll Instagram container until processing status is FINISHED or EXPIRED
     */
    protected function waitForInstagramContainerReady(string $containerId, string $accessToken, int $maxAttempts = 25): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $res = Http::withToken($accessToken)
                ->timeout(15)
                ->get("{$this->baseUrl}/{$containerId}", [
                    'fields' => 'status_code,status',
                ]);

            if ($res->successful()) {
                $status = $res->json('status_code');
                if ($status === 'FINISHED') {
                    return;
                }
                if ($status === 'ERROR' || $status === 'EXPIRED') {
                    $errMsg = $res->json('status', 'Video processing failed in Instagram.');
                    throw new Exception("Instagram media container failed with status: {$errMsg}");
                }
            }

            sleep(4);
        }

        throw new Exception("Instagram video processing timed out after waiting for media container transcoding.");
    }

    /**
     * Fetch comments from Facebook Post or Instagram Media
     */
    public function getComments(string $platform, string $postOrMediaId, string $accessToken): array
    {
        $fields = $platform === 'instagram'
            ? 'id,text,timestamp,from{id,username},like_count,replies{id,text,timestamp,from{id,username}}'
            : 'id,message,created_time,from{id,name,picture},like_count,comments{id,message,created_time,from{id,name,picture}}';

        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->get("{$this->baseUrl}/{$postOrMediaId}/comments", [
                'fields' => $fields,
                'limit' => 50,
            ]);

        if ($response->failed()) {
            $err = $response->json('error', []);
            $code = $err['code'] ?? null;
            $subcode = $err['error_subcode'] ?? null;

            // Error 100, subcode 33: Object does not exist, was deleted on Facebook, or lacks permission
            if ($code == 100 && $subcode == 33) {
                Log::info("[MetaGraphApi] Object {$postOrMediaId} no longer exists or is inaccessible on {$platform} (code 100/33). Skipping comments.");
                return ['__deleted__' => true];
            }

            Log::warning("[MetaGraphApi] Failed to fetch comments for {$postOrMediaId}: " . $response->body());
            return [];
        }

        return $response->json('data', []);
    }

    /**
     * Post reply to a Facebook or Instagram comment
     */
    public function replyToComment(string $platform, string $commentId, string $message, string $accessToken): array
    {
        $endpoint = $platform === 'instagram'
            ? "{$this->baseUrl}/{$commentId}/replies"
            : "{$this->baseUrl}/{$commentId}/comments";

        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->post($endpoint, [
                'message' => $message,
            ]);

        return $this->handleResponse($response, 'Comment Reply');
    }

    /**
     * Create a top-level comment on a post/media (used for automated first comments / hashtags)
     */
    public function createPostComment(string $platform, string $postOrMediaId, string $message, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->post("{$this->baseUrl}/{$postOrMediaId}/comments", [
                'message' => $message,
            ]);

        return $this->handleResponse($response, 'Post First Comment');
    }

    /**
     * Retrieve live post engagement metrics (likes, comments, etc.) from Meta
     */
    public function getPostMetrics(
        string $platform,
        string $postOrMediaId,
        string $accessToken,
        ?string $pageOrAccountId = null
    ): array
    {
        try {
            if ($platform === 'instagram') {
                $response = Http::withToken($accessToken)
                    ->timeout(15)
                    ->get("{$this->baseUrl}/{$postOrMediaId}", [
                        'fields' => 'id,like_count,comments_count,permalink,media_type',
                    ]);

                if ($response->successful()) {
                    $likes = (int) $response->json('like_count', 0);
                    $comments = (int) $response->json('comments_count', 0);
                    $permalink = $response->json('permalink');

                    $reach = null;
                    $impressions = null;
                    $shares = 0;
                    try {
                        // 1. Try modern v22+ metrics (views replaces deprecated impressions)
                        $insightsRes = Http::withToken($accessToken)
                            ->timeout(8)
                            ->get("{$this->baseUrl}/{$postOrMediaId}/insights", [
                                'metric' => 'reach,views,saved,shares',
                            ]);

                        // 2. Fallback to legacy v21/v20 metrics or simplified metrics if unsupported
                        if ($insightsRes->failed()) {
                            $insightsRes = Http::withToken($accessToken)
                                ->timeout(8)
                                ->get("{$this->baseUrl}/{$postOrMediaId}/insights", [
                                    'metric' => 'reach,impressions,saved,shares',
                                ]);
                        }

                        if ($insightsRes->failed()) {
                            $insightsRes = Http::withToken($accessToken)
                                ->timeout(8)
                                ->get("{$this->baseUrl}/{$postOrMediaId}/insights", [
                                    'metric' => 'reach',
                                ]);
                        }

                        if ($insightsRes->successful()) {
                            foreach ($insightsRes->json('data', []) as $item) {
                                $name = $item['name'] ?? '';
                                $val = $item['values'][0]['value'] ?? null;
                                if ($name === 'reach' && $val !== null) {
                                    $reach = (int) $val;
                                } elseif (($name === 'views' || $name === 'impressions') && $val !== null) {
                                    $impressions = (int) $val;
                                } elseif ($name === 'shares' && $val !== null) {
                                    $shares = (int) $val;
                                }
                            }
                        }
                    } catch (\Throwable) {}

                    return [
                        'likes' => $likes,
                        'comments' => $comments,
                        'shares' => $shares,
                        'permalink' => $permalink,
                        'reach' => $reach,
                        'impressions' => $impressions,
                    ];
                }
            } else {
                // Facebook Post, Feed Object, or Photo
                $isFeedPost = str_contains($postOrMediaId, '_');
                $likes = 0;
                $comments = 0;
                $shares = 0;
                $link = null;

                if ($isFeedPost) {
                    $response = Http::withToken($accessToken)
                        ->timeout(15)
                        ->get("{$this->baseUrl}/{$postOrMediaId}", [
                            'fields' => 'id,permalink_url,shares,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)',
                        ]);

                    if ($response->successful()) {
                        $likes = (int) $response->json('reactions.summary.total_count', 0);
                        $comments = (int) $response->json('comments.summary.total_count', 0);
                        $shares = (int) $response->json('shares.count', 0);
                        $link = $response->json('permalink_url');
                    }
                } else {
                    $response = Http::withToken($accessToken)
                        ->timeout(15)
                        ->get("{$this->baseUrl}/{$postOrMediaId}", [
                            'fields' => 'id,link,likes.summary(true),comments.summary(true)',
                        ]);

                    if ($response->successful()) {
                        $likes = (int) $response->json('likes.summary.total_count', 0);
                        $comments = (int) $response->json('comments.summary.total_count', 0);
                        $link = $response->json('link');
                    }
                }

                $reach = null;
                $impressions = null;

                // Resolve the feed post ID for insights:
                // If it contains '_', it's already a feed post ID ({page_id}_{post_id}).
                // Otherwise (a Photo ID), resolve the corresponding published feed post on the page by attachment matching.
                $feedPostId = $isFeedPost ? $postOrMediaId : null;

                if (!$feedPostId && $pageOrAccountId) {
                    try {
                        $postsRes = Http::withToken($accessToken)
                            ->timeout(10)
                            ->get("{$this->baseUrl}/{$pageOrAccountId}/published_posts", [
                                'limit' => 10,
                                'fields' => 'id,attachments{target}',
                            ]);
                        if ($postsRes->successful()) {
                            foreach ($postsRes->json('data', []) as $p) {
                                $targetId = $p['attachments']['data'][0]['target']['id'] ?? null;
                                if ($targetId === $postOrMediaId) {
                                    $feedPostId = $p['id'];
                                    break;
                                }
                            }
                            if (!$feedPostId) {
                                $feedPostId = $postsRes->json('data.0.id');
                            }
                        }
                    } catch (\Throwable) {}
                }

                if ($feedPostId) {
                    try {
                        // Facebook Unique Content Viewers (Reach)
                        $reachRes = Http::withToken($accessToken)
                            ->timeout(8)
                            ->get("{$this->baseUrl}/{$feedPostId}/insights/post_total_media_view_unique");
                        if ($reachRes->successful() && $reachRes->json('data.0.values.0.value') !== null) {
                            $reach = (int) $reachRes->json('data.0.values.0.value');
                        } else {
                            $reachFallback = Http::withToken($accessToken)
                                ->timeout(8)
                                ->get("{$this->baseUrl}/{$feedPostId}/insights/post_impressions_unique");
                            if ($reachFallback->successful() && $reachFallback->json('data.0.values.0.value') !== null) {
                                $reach = (int) $reachFallback->json('data.0.values.0.value');
                            }
                        }

                        // Facebook Total Content Views (Impressions)
                        $impRes = Http::withToken($accessToken)
                            ->timeout(8)
                            ->get("{$this->baseUrl}/{$feedPostId}/insights/post_media_view");
                        if ($impRes->successful() && $impRes->json('data.0.values.0.value') !== null) {
                            $impressions = (int) $impRes->json('data.0.values.0.value');
                        } else {
                            $impFallback = Http::withToken($accessToken)
                                ->timeout(8)
                                ->get("{$this->baseUrl}/{$feedPostId}/insights/post_impressions");
                            if ($impFallback->successful() && $impFallback->json('data.0.values.0.value') !== null) {
                                $impressions = (int) $impFallback->json('data.0.values.0.value');
                            }
                        }
                    } catch (\Throwable) {}
                }

                return [
                    'likes' => $likes,
                    'comments' => $comments,
                    'shares' => $shares,
                    'permalink' => $link,
                    'reach' => $reach,
                    'impressions' => $impressions,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("[MetaGraphApi] Failed to fetch metrics for {$platform} object {$postOrMediaId}: " . $e->getMessage());
        }

        return [
            'likes' => 0,
            'comments' => 0,
            'shares' => 0,
            'reach' => null,
            'impressions' => null,
        ];
    }

    /**
     * Fetch conversations for Facebook Page or Instagram Business Account
     */
    public function getConversations(
        string $platform,
        string $accountId,
        string $accessToken,
        int $limit = 50,
        ?string $linkedPageId = null
    ): array {
        $fields = 'id,updated_time,participants,snippet,unread_count,messages.limit(20){id,message,created_time,from,to,attachments{id,mime_type,name,image_data,video_data,file_url}}';

        if ($platform === 'instagram') {
            // Attempt 1: Query Instagram User ID directly (standard IG Graph API)
            $response = Http::withToken($accessToken)
                ->timeout(25)
                ->get("{$this->baseUrl}/{$accountId}/conversations", [
                    'fields' => $fields,
                    'limit' => $limit,
                ]);

            if ($response->successful() && !empty($response->json('data'))) {
                Log::info("[MetaGraphApi] Retrieved " . count($response->json('data')) . " Instagram conversations directly for account {$accountId}");
                return $response->json('data', []);
            }

            // Attempt 2: Query linked Facebook Page ID with platform=instagram (Meta Messenger Platform for IG)
            $pageIdToQuery = $linkedPageId ?: $accountId;
            $pageResponse = Http::withToken($accessToken)
                ->timeout(25)
                ->get("{$this->baseUrl}/{$pageIdToQuery}/conversations", [
                    'platform' => 'instagram',
                    'fields' => $fields,
                    'limit' => $limit,
                ]);

            if ($pageResponse->successful() && !empty($pageResponse->json('data'))) {
                Log::info("[MetaGraphApi] Retrieved " . count($pageResponse->json('data')) . " Instagram conversations via Page {$pageIdToQuery}");
                return $pageResponse->json('data', []);
            }

            // Attempt 3: If accountId wasn't the page, also try accountId with platform=instagram
            if ($pageIdToQuery !== $accountId) {
                $hybridResponse = Http::withToken($accessToken)
                    ->timeout(25)
                    ->get("{$this->baseUrl}/{$accountId}/conversations", [
                        'platform' => 'instagram',
                        'fields' => $fields,
                        'limit' => $limit,
                    ]);

                if ($hybridResponse->successful() && !empty($hybridResponse->json('data'))) {
                    return $hybridResponse->json('data', []);
                }
            }

            Log::info("[MetaGraphApi] Instagram conversations query completed for {$accountId}. Status: " . ($response->status() ?? 'N/A') . ", Body: " . substr($response->body(), 0, 200));
            return $response->json('data', []);
        }

        // Standard Facebook Page conversations
        $response = Http::withToken($accessToken)
            ->timeout(25)
            ->get("{$this->baseUrl}/{$accountId}/conversations", [
                'fields' => $fields,
                'limit' => $limit,
            ]);

        if ($response->failed()) {
            Log::warning("[MetaGraphApi] Failed to fetch conversations for {$platform} account {$accountId}: " . $response->body());
            return [];
        }

        return $response->json('data', []);
    }

    /**
     * Fetch messages for a specific conversation thread
     */
    public function getConversationMessages(string $platform, string $conversationId, string $accessToken, int $limit = 50): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->get("{$this->baseUrl}/{$conversationId}/messages", [
                'fields' => 'id,message,created_time,from,to,attachments{id,mime_type,name,image_data,video_data,file_url}',
                'limit' => $limit,
            ]);

        if ($response->failed()) {
            Log::warning("[MetaGraphApi] Failed to fetch messages for conversation {$conversationId}: " . $response->body());
            return [];
        }

        return $response->json('data', []);
    }

    /**
     * Send direct message (text or media) to a recipient on Facebook Messenger or Instagram Direct
     */
    public function sendMessage(
        string $platform,
        string $accountId,
        string $recipientId,
        ?string $text,
        ?string $attachmentUrl = null,
        ?string $attachmentType = 'image',
        string $accessToken = ''
    ): array {
        // Resolve local file bytes if attachment is provided
        $attachmentBytes = null;
        $attachmentFilename = 'attachment.jpg';
        $mimeType = 'image/jpeg';

        if (!empty($attachmentUrl)) {
            $parsedPath = parse_url($attachmentUrl, PHP_URL_PATH);
            if ($parsedPath && str_contains($parsedPath, '/storage/')) {
                $relPath = substr($parsedPath, strpos($parsedPath, '/storage/') + 9);
                if (Storage::disk('public')->exists($relPath)) {
                    $attachmentBytes = Storage::disk('public')->get($relPath);
                    $attachmentFilename = basename($relPath);
                    $mimeType = Storage::disk('public')->mimeType($relPath) ?: 'image/jpeg';
                }
            }
        }

        // Strategy A: Facebook with local binary attachment -> Direct multipart form-data upload
        // This avoids external ngrok scraper issues (Code 100/Subcode 2018047: Upload attachment failure)
        if ($platform === 'facebook' && $attachmentBytes) {
            $req = Http::withToken($accessToken)->timeout(60);
            $req->attach('filedata', $attachmentBytes, $attachmentFilename, ['Content-Type' => $mimeType]);

            $response = $req->post("{$this->baseUrl}/{$accountId}/messages", [
                'recipient' => json_encode(['id' => $recipientId]),
                'messaging_type' => 'RESPONSE',
                'message' => json_encode([
                    'attachment' => [
                        'type' => $attachmentType ?? 'image',
                        'payload' => (object)[],
                    ],
                ]),
            ]);

            $resData = $this->handleResponse($response, "{$platform} Send Message");

            // If user typed text along with the attachment, send it as a follow-up message
            if (!empty($text)) {
                try {
                    Http::withToken($accessToken)->timeout(30)->post("{$this->baseUrl}/{$accountId}/messages", [
                        'recipient' => ['id' => $recipientId],
                        'messaging_type' => 'RESPONSE',
                        'message' => ['text' => $text],
                    ]);
                } catch (\Throwable $textEx) {
                    Log::warning("[MetaGraphApi] Follow-up text send notice: " . $textEx->getMessage());
                }
            }

            return $resData;
        }

        // Strategy B: Standard JSON payload (Instagram, Text-only, or Public URL)
        $payload = [
            'recipient' => ['id' => $recipientId],
        ];

        if ($platform === 'facebook') {
            $payload['messaging_type'] = 'RESPONSE';
        }

        if ($attachmentUrl) {
            $appUrl = config('app.url');
            $cleanUrl = $attachmentUrl;
            if (!empty($appUrl) && (str_contains($cleanUrl, '127.0.0.1') || str_contains($cleanUrl, 'localhost') || str_starts_with($cleanUrl, '/'))) {
                $p = preg_replace('#^https?://[^/]+/#', '', $cleanUrl);
                $cleanUrl = rtrim($appUrl, '/') . '/' . ltrim($p, '/');
            }

            $payload['message'] = [
                'attachment' => [
                    'type' => $attachmentType ?? 'image',
                    'payload' => [
                        'url' => $cleanUrl,
                        'is_reusable' => true,
                    ],
                ],
            ];
        } elseif ($text) {
            $payload['message'] = [
                'text' => $text,
            ];
        } else {
            throw new Exception("Message content or attachment is required.");
        }

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->post("{$this->baseUrl}/{$accountId}/messages", $payload);

        $resData = $this->handleResponse($response, "{$platform} Send Message");

        if ($attachmentUrl && !empty($text)) {
            try {
                $textPayload = [
                    'recipient' => ['id' => $recipientId],
                    'message' => ['text' => $text],
                ];
                if ($platform === 'facebook') {
                    $textPayload['messaging_type'] = 'RESPONSE';
                }
                Http::withToken($accessToken)->timeout(30)->post("{$this->baseUrl}/{$accountId}/messages", $textPayload);
            } catch (\Throwable $textEx) {
                Log::warning("[MetaGraphApi] Follow-up text send notice: " . $textEx->getMessage());
            }
        }

        return $resData;
    }

    /**
     * Fetch sender/customer profile information (name, avatar, username)
     */
    public function getSenderProfile(string $platform, string $senderId, string $accessToken): ?array
    {
        try {
            $fields = $platform === 'instagram'
                ? 'name,username,profile_pic'
                : 'first_name,last_name,name,profile_pic';

            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get("{$this->baseUrl}/{$senderId}", [
                    'fields' => $fields,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $name = $data['name'] ?? trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
                return [
                    'name' => $name ?: 'Customer',
                    'username' => $data['username'] ?? null,
                    'profile_pic' => $data['profile_pic'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::debug("[MetaGraphApi] Could not fetch profile for sender {$senderId}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Subscribe Facebook Page to Webhooks (messages, feeds, comments, leadgen)
     */
    public function subscribePageToWebhooks(string $pageId, string $accessToken): bool
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->post("{$this->baseUrl}/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => [
                        'messages',
                        'messaging_postbacks',
                        'message_deliveries',
                        'message_reads',
                        'feed',
                        'leadgen',
                    ],
                ]);

            return $response->successful() && $response->json('success') === true;
        } catch (\Throwable $e) {
            Log::warning("[MetaGraphApi] Failed to subscribe page {$pageId} to webhooks: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Build Lead Ads OAuth Authorization URL requesting specific lead retrieval and ads permissions
     */
    public function getLeadAdsAuthorizationUrl(string $redirectUri, string $state): string
    {
        $configId = (string) config('services.facebook.config_id', env('FACEBOOK_CONFIG_ID', '1908588223842822'));

        $queryParams = [
            'client_id' => $this->appId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'auth_type' => 'rerequest',
            'state' => $state,
        ];

        if (!empty($configId)) {
            $queryParams['config_id'] = $configId;
        } else {
            $scopes = [
                'leads_retrieval',
                'pages_show_list',
                'pages_read_engagement',
                'pages_manage_ads',
                'pages_manage_metadata',
                'ads_management',
                'ads_read',
                'business_management',
            ];
            $queryParams['scope'] = implode(',', $scopes);
        }

        $queryString = http_build_query($queryParams);
        return "https://www.facebook.com/{$this->graphVersion}/dialog/oauth?{$queryString}";
    }

    /**
     * Inspect granted permissions on access token (supports both Page tokens and User tokens)
     */
    public function checkTokenPermissions(string $accessToken): array
    {
        try {
            // 1. debug_token endpoint is the official Meta method for inspecting Page and User tokens
            $debug = $this->debugToken($accessToken);
            if (!empty($debug['is_valid'])) {
                $scopes = $debug['scopes'] ?? [];
                return [
                    'granted' => $scopes,
                    'declined' => [],
                    'has_leads_retrieval' => in_array('leads_retrieval', $scopes, true),
                    'has_pages_manage_ads' => in_array('pages_manage_ads', $scopes, true),
                ];
            }

            // 2. Fallback to /me/permissions for direct User Access Tokens
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->get("{$this->baseUrl}/me/permissions");

            if ($response->successful()) {
                $items = $response->json('data', []);
                $granted = [];
                $declined = [];

                foreach ($items as $item) {
                    if (($item['status'] ?? '') === 'granted') {
                        $granted[] = $item['permission'];
                    } else {
                        $declined[] = $item['permission'];
                    }
                }

                return [
                    'granted' => $granted,
                    'declined' => $declined,
                    'has_leads_retrieval' => in_array('leads_retrieval', $granted, true),
                    'has_pages_manage_ads' => in_array('pages_manage_ads', $granted, true),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("[MetaGraphApi] Failed to check token permissions: " . $e->getMessage());
        }

        return [
            'granted' => [],
            'declined' => [],
            'has_leads_retrieval' => false,
            'has_pages_manage_ads' => false,
        ];
    }

    /**
     * Retrieve Leadgen Forms belonging to a Facebook Page
     */
    public function getLeadgenForms(string $pageId, string $pageAccessToken): array
    {
        $fields = implode(',', [
            'id',
            'name',
            'status',
            'leads_count',
            'created_time',
            'questions{id,key,label,type,options}',
            'locale',
            'privacy_policy_url',
            'expired_leads_count',
        ]);

        $response = Http::withToken($pageAccessToken)
            ->timeout(20)
            ->get("{$this->baseUrl}/{$pageId}/leadgen_forms", [
                'fields' => $fields,
                'limit' => 100,
            ]);

        if ($response->failed()) {
            $error = $response->json('error.message', $response->body());
            Log::error("[MetaGraphApi] Failed to fetch leadgen forms for page {$pageId}: {$error}");
            throw new Exception("Meta Lead Ads Error: {$error}");
        }

        return $response->json('data', []);
    }

    /**
     * Retrieve individual Leadgen Form details with questions
     */
    public function getLeadgenFormDetails(string $formId, string $pageAccessToken): array
    {
        $fields = implode(',', [
            'id',
            'name',
            'status',
            'leads_count',
            'created_time',
            'questions{id,key,label,type,options}',
            'locale',
            'privacy_policy_url',
        ]);

        $response = Http::withToken($pageAccessToken)
            ->timeout(15)
            ->get("{$this->baseUrl}/{$formId}", [
                'fields' => $fields,
            ]);

        return $this->handleResponse($response, "Fetch Leadgen Form {$formId}");
    }

    /**
     * Retrieve submitted lead data from Meta Graph API using leadgen_id
     */
    public function getLeadData(string $leadgenId, string $accessToken): array
    {
        $fields = implode(',', [
            'id',
            'created_time',
            'ad_id',
            'ad_name',
            'adset_id',
            'adset_name',
            'campaign_id',
            'campaign_name',
            'form_id',
            'field_data',
            'is_organic',
            'platform',
        ]);

        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->get("{$this->baseUrl}/{$leadgenId}", [
                'fields' => $fields,
            ]);

        return $this->handleResponse($response, "Fetch Lead Data {$leadgenId}");
    }

    /**
     * Retrieve historical leads for a given form with pagination
     */
    public function getHistoricalLeads(string $formId, string $pageAccessToken, int $limit = 50, ?string $after = null): array
    {
        $fields = implode(',', [
            'id',
            'created_time',
            'ad_id',
            'ad_name',
            'adset_id',
            'adset_name',
            'campaign_id',
            'campaign_name',
            'form_id',
            'field_data',
            'is_organic',
            'platform',
        ]);

        $queryParams = [
            'fields' => $fields,
            'limit' => min($limit, 100),
        ];

        if ($after) {
            $queryParams['after'] = $after;
        }

        $response = Http::withToken($pageAccessToken)
            ->timeout(30)
            ->get("{$this->baseUrl}/{$formId}/leads", $queryParams);

        return $this->handleResponse($response, "Fetch Historical Leads for Form {$formId}");
    }

    /**
     * Retrieve user's accessible Ad Accounts
     */
    public function getAdAccounts(string $userAccessToken): array
    {
        try {
            $fields = implode(',', [
                'id',
                'account_id',
                'name',
                'account_status',
                'currency',
                'timezone_name',
                'business{id,name}',
            ]);

            $response = Http::withToken($userAccessToken)
                ->timeout(20)
                ->get("{$this->baseUrl}/me/adaccounts", [
                    'fields' => $fields,
                    'limit' => 50,
                ]);

            if ($response->successful()) {
                return $response->json('data', []);
            }
        } catch (\Throwable $e) {
            Log::warning("[MetaGraphApi] Failed to fetch Ad Accounts: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Standard response evaluator
     */
    protected function handleResponse($response, string $operationName): array
    {
        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            $code = $response->json('error.code') ?? $response->status();
            $errorSubcode = $response->json('error.error_subcode') ?? '';

            Log::error("[MetaGraphApi] {$operationName} failed (Code {$code}/Subcode {$errorSubcode}): {$error}");
            throw new Exception("Meta API error during {$operationName}: {$error} (Code {$code})");
        }

        return $response->json();
    }
}

