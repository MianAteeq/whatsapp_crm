<?php

namespace App\Http\Controllers\Api\Builder;

use App\Http\Controllers\Controller;
use App\Services\Builder\PageBuilderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

class PageBuilderController extends Controller
{
    public function __construct(
        protected PageBuilderService $pageService
    ) {}

    /**
     * Get list of landing pages
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $filters = $request->only(['search', 'status', 'category', 'sort_by', 'sort_order', 'per_page']);

        $pages = $this->pageService->getPages($tenantId, $filters);

        return response()->json([
            'success' => true,
            'data' => $pages->items(),
            'meta' => [
                'current_page' => $pages->currentPage(),
                'last_page' => $pages->lastPage(),
                'total' => $pages->total(),
                'per_page' => $pages->perPage(),
            ],
        ]);
    }

    /**
     * Create a new landing page
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $userId = (int) $request->user()->id;

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'string', 'in:draft,published,archived'],
            'theme' => ['nullable', 'array'],
            'sections' => ['nullable', 'array'],
            'seo_settings' => ['nullable', 'array'],
        ]);

        try {
            $page = $this->pageService->createPage($tenantId, $userId, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Landing page created successfully.',
                'data' => $page,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get single landing page details for editing
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $page = \App\Models\BuilderPage::forTenant($tenantId)
            ->with(['forms:id,page_id,title,slug,form_type'])
            ->withCount(['submissions'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }

    /**
     * Update landing page
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'string', 'in:draft,published,archived'],
            'theme' => ['nullable', 'array'],
            'sections' => ['nullable', 'array'],
            'seo_settings' => ['nullable', 'array'],
        ]);

        try {
            $page = $this->pageService->updatePage($tenantId, $id, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Landing page updated successfully.',
                'data' => $page,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Toggle publish state
     */
    public function togglePublish(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        try {
            $page = $this->pageService->togglePublish($tenantId, $id);

            return response()->json([
                'success' => true,
                'message' => $page->status === \App\Models\BuilderPage::STATUS_PUBLISHED
                    ? 'Page published successfully.'
                    : 'Page moved to drafts.',
                'data' => $page,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Clone an existing page
     */
    public function clone(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $userId = (int) $request->user()->id;

        try {
            $page = $this->pageService->clonePage($tenantId, $id, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Landing page duplicated successfully.',
                'data' => $page,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete landing page
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $this->pageService->deletePage($tenantId, $id);

        return response()->json([
            'success' => true,
            'message' => 'Landing page deleted successfully.',
        ]);
    }
}

