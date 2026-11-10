<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostMedia;
use App\Models\SocialPostTarget;
use App\Jobs\PublishSocialPostJob;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class SocialPostController extends Controller
{
    /**
     * List posts with filtering and pagination
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $query = SocialPost::forTenant($tenantId)
            ->with(['media', 'targets.account:id,platform,name,username,profile_picture_url'])
            ->latest('id');

        // Status filter
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Platform filter
        if ($request->filled('platform') && in_array($request->platform, ['facebook', 'instagram'])) {
            $platform = $request->platform;
            $query->whereHas('targets', function ($q) use ($platform) {
                $q->where('platform', $platform);
            });
        }

        // Search in content
        if ($request->filled('search')) {
            $term = trim($request->search);
            $query->where('content', 'like', "%{$term}%");
        }

        // Date range filters
        if ($request->filled('start_date')) {
            $query->where('scheduled_at', '>=', Carbon::parse($request->start_date)->startOfDay());
        }
        if ($request->filled('end_date')) {
            $query->where('scheduled_at', '<=', Carbon::parse($request->end_date)->endOfDay());
        }

        $perPage = (int) $request->input('per_page', 15);
        $posts = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * Fetch calendar items within a date window (Month, Week, Day)
     */
    public function calendar(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date'))->startOfDay() : now()->startOfMonth();
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date'))->endOfDay() : now()->endOfMonth();

        $query = SocialPost::forTenant($tenantId)
            ->with(['media', 'targets.account:id,platform,name,username,profile_picture_url'])
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('scheduled_at', [$startDate, $endDate])
                  ->orWhereBetween('published_at', [$startDate, $endDate]);
            });

        if ($request->filled('platform') && in_array($request->platform, ['facebook', 'instagram'])) {
            $platform = $request->platform;
            $query->whereHas('targets', function ($tq) use ($platform) {
                $tq->where('platform', $platform);
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $posts = $query->orderBy('scheduled_at')->get();

        return response()->json([
            'success' => true,
            'data' => $posts,
        ]);
    }

    /**
     * Create a new post (Draft, Scheduled, or Publish Now)
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $userId = (int) $request->user()->id;

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:5000'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer'],
            'scheduled_at' => ['nullable', 'date'],
            'publish_now' => ['nullable', 'boolean'],
            'media' => ['nullable', 'array'],
            'media.*.url' => ['required', 'string'],
            'media.*.file_path' => ['nullable', 'string'],
            'media.*.filename' => ['required', 'string'],
            'media.*.media_type' => ['required', 'string', 'in:image,video'],
            'media.*.mime_type' => ['required', 'string'],
            'media.*.file_size' => ['nullable', 'integer'],
            'media.*.width' => ['nullable', 'integer'],
            'media.*.height' => ['nullable', 'integer'],
            'media.*.aspect_ratio' => ['nullable', 'string'],
            'options' => ['nullable', 'array'],
        ]);

        // Verify all target accounts belong to this tenant and are active
        $accounts = SocialAccount::forTenant($tenantId)
            ->active()
            ->whereIn('id', $validated['account_ids'])
            ->get();

        if ($accounts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Please select at least one valid, active social media account.',
            ], 422);
        }

        // Validate platform-specific rules (Instagram requires media)
        $hasInstagram = $accounts->contains('platform', 'instagram');
        $hasMedia = !empty($validated['media']);
        if ($hasInstagram && !$hasMedia) {
            return response()->json([
                'success' => false,
                'message' => 'Instagram does not support text-only posts. Please attach at least one photo or video.',
            ], 422);
        }

        $publishNow = $request->boolean('publish_now');
        $scheduledAt = !empty($validated['scheduled_at']) ? Carbon::parse($validated['scheduled_at']) : null;

        $initialStatus = $publishNow
            ? SocialPost::STATUS_PUBLISHING
            : ($scheduledAt ? SocialPost::STATUS_SCHEDULED : SocialPost::STATUS_DRAFT);

        return DB::transaction(function () use ($tenantId, $userId, $validated, $accounts, $initialStatus, $scheduledAt, $publishNow) {
            $post = SocialPost::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'content' => $validated['content'] ?? null,
                'status' => $initialStatus,
                'scheduled_at' => $scheduledAt,
                'published_at' => null,
                'options' => $validated['options'] ?? null,
            ]);

            // Save Media Items
            if (!empty($validated['media'])) {
                foreach ($validated['media'] as $idx => $m) {
                    SocialPostMedia::create([
                        'tenant_id' => $tenantId,
                        'post_id' => $post->id,
                        'media_type' => $m['media_type'],
                        'url' => $m['url'],
                        'file_path' => $m['file_path'] ?? '',
                        'filename' => $m['filename'],
                        'file_size' => $m['file_size'] ?? 0,
                        'mime_type' => $m['mime_type'],
                        'width' => $m['width'] ?? null,
                        'height' => $m['height'] ?? null,
                        'aspect_ratio' => $m['aspect_ratio'] ?? null,
                        'order' => $idx,
                    ]);
                }
            }

            // Save Targets
            foreach ($accounts as $account) {
                SocialPostTarget::create([
                    'tenant_id' => $tenantId,
                    'post_id' => $post->id,
                    'social_account_id' => $account->id,
                    'platform' => $account->platform,
                    'status' => $publishNow ? SocialPostTarget::STATUS_PUBLISHING : SocialPostTarget::STATUS_PENDING,
                ]);
            }

            // Dispatch Immediate Publishing if requested
            if ($publishNow) {
                PublishSocialPostJob::dispatch($post);
            }

            $post->load(['media', 'targets.account']);

            return response()->json([
                'success' => true,
                'message' => $publishNow
                    ? 'Post publication initiated.'
                    : ($scheduledAt ? 'Post scheduled successfully.' : 'Post saved as draft.'),
                'data' => $post,
            ], 201);
        });
    }

    /**
     * Get single post details with media, targets, and audit logs
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $post = SocialPost::forTenant($tenantId)
            ->with(['media', 'targets.account', 'logs'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $post,
        ]);
    }

    /**
     * Reschedule post
     */
    public function reschedule(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'scheduled_at' => ['required', 'date'],
        ]);

        $post = SocialPost::forTenant($tenantId)->findOrFail($id);

        if ($post->status === SocialPost::STATUS_PUBLISHED) {
            return response()->json([
                'success' => false,
                'message' => 'Published posts cannot be rescheduled.',
            ], 422);
        }

        $scheduledAt = Carbon::parse($request->scheduled_at);

        $post->update([
            'scheduled_at' => $scheduledAt,
            'status' => SocialPost::STATUS_SCHEDULED,
            'error_message' => null,
        ]);

        // Reset targets status to pending
        $post->targets()->update([
            'status' => SocialPostTarget::STATUS_PENDING,
            'error_message' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Post rescheduled successfully.',
            'data' => $post->load(['media', 'targets.account']),
        ]);
    }

    /**
     * Trigger immediate publishing for a draft or scheduled post
     */
    public function publishNow(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $post = SocialPost::forTenant($tenantId)->findOrFail($id);

        if ($post->status === SocialPost::STATUS_PUBLISHED) {
            return response()->json([
                'success' => false,
                'message' => 'Post has already been published.',
            ], 422);
        }

        $post->update([
            'status' => SocialPost::STATUS_PUBLISHING,
            'error_message' => null,
        ]);
        $post->targets()->update([
            'status' => SocialPostTarget::STATUS_PUBLISHING,
            'error_message' => null,
        ]);

        PublishSocialPostJob::dispatch($post);

        return response()->json([
            'success' => true,
            'message' => 'Publishing job dispatched.',
            'data' => $post->load(['media', 'targets.account']),
        ]);
    }

    /**
     * Delete a post
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $post = SocialPost::forTenant($tenantId)->findOrFail($id);

        $post->delete();

        return response()->json([
            'success' => true,
            'message' => 'Post removed successfully.',
        ]);
    }
}

