<?php

namespace App\Http\Controllers\Api\Media;

use App\Http\Controllers\Controller;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MediaController extends Controller
{
    public function __construct(
        protected MediaStorageService $mediaService
    ) {}

    protected function getTenantId(Request $request): int
    {
        return (int) ($request->user()?->tenant_id ?? 1);
    }

    /**
     * Get storage quota and statistics overview
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $stats = $this->mediaService->getStorageStats($tenantId);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Throwable $e) {
            Log::error('MediaController@getStats failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve media statistics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get folder tree hierarchy
     */
    public function getFolders(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $folders = $this->mediaService->getFolders($tenantId);

            return response()->json([
                'success' => true,
                'data' => $folders,
            ]);
        } catch (\Throwable $e) {
            Log::error('MediaController@getFolders failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load folders: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new folder
     */
    public function createFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'parent_id' => 'nullable|integer|exists:media_folders,id',
            'color' => 'nullable|string|max:30',
        ]);

        try {
            $tenantId = $this->getTenantId($request);
            $folder = $this->mediaService->createFolder($tenantId, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Folder created successfully.',
                'data' => $folder,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('MediaController@createFolder failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create folder: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Rename or update folder
     */
    public function updateFolder(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'parent_id' => 'nullable|integer',
            'color' => 'nullable|string|max:30',
        ]);

        try {
            $tenantId = $this->getTenantId($request);
            $folder = $this->mediaService->updateFolder($tenantId, $id, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Folder updated successfully.',
                'data' => $folder,
            ]);
        } catch (\Throwable $e) {
            Log::error('MediaController@updateFolder failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update folder: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete folder
     */
    public function deleteFolder(Request $request, int $id): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $this->mediaService->deleteFolder($tenantId, $id);

            return response()->json([
                'success' => true,
                'message' => 'Folder deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            Log::error('MediaController@deleteFolder failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete folder: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List media assets with search, sorting and filters
     */
    public function getAssets(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $filters = $request->only(['folder_id', 'file_type', 'search', 'tag', 'sort_by', 'sort_order', 'per_page']);
            $assets = $this->mediaService->getAssets($tenantId, $filters);

            return response()->json([
                'success' => true,
                'data' => $assets->items(),
                'pagination' => [
                    'current_page' => $assets->currentPage(),
                    'last_page' => $assets->lastPage(),
                    'per_page' => $assets->perPage(),
                    'total' => $assets->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('MediaController@getAssets failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load assets: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload one or multiple files
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'files' => 'nullable|array',
            'files.*' => 'file|max:102400', // max 100MB per file
            'file' => 'nullable|file|max:102400',
            'folder_id' => 'nullable|integer',
            'tags' => 'nullable|array',
        ]);

        try {
            $tenantId = $this->getTenantId($request);
            $userId = $request->user()?->id;
            $folderId = $request->input('folder_id') ? (int) $request->input('folder_id') : null;
            $tags = $request->input('tags', []);

            $files = [];
            if ($request->hasFile('files')) {
                $files = $request->file('files');
            } elseif ($request->hasFile('file')) {
                $files = [$request->file('file')];
            }

            if (empty($files)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No files uploaded.',
                ], 422);
            }

            $savedAssets = [];
            foreach ($files as $file) {
                $asset = $this->mediaService->storeUpload($tenantId, $file, $folderId, $userId, $tags);
                $savedAssets[] = $asset;
            }

            return response()->json([
                'success' => true,
                'message' => count($savedAssets) . ' file(s) uploaded successfully.',
                'data' => $savedAssets,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('MediaController@upload failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Update asset metadata (name, tags, folder)
     */
    public function updateAsset(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'original_name' => 'nullable|string|max:255',
            'media_folder_id' => 'nullable|integer',
            'tags' => 'nullable|array',
        ]);

        try {
            $tenantId = $this->getTenantId($request);
            $asset = $this->mediaService->updateAsset($tenantId, $id, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Media updated successfully.',
                'data' => $asset,
            ]);
        } catch (\Throwable $e) {
            Log::error('MediaController@updateAsset failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update media: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Soft delete an asset
     */
    public function deleteAsset(Request $request, int $id): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $this->mediaService->deleteAsset($tenantId, $id);

            return response()->json([
                'success' => true,
                'message' => 'Media deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            Log::error('MediaController@deleteAsset failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete media: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk move assets
     */
    public function bulkMove(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset_ids' => 'required|array',
            'asset_ids.*' => 'integer',
            'folder_id' => 'nullable|integer',
        ]);

        try {
            $tenantId = $this->getTenantId($request);
            $count = $this->mediaService->bulkMove($tenantId, $validated['asset_ids'], $validated['folder_id'] ?? null);

            return response()->json([
                'success' => true,
                'message' => "{$count} file(s) moved successfully.",
            ]);
        } catch (\Throwable $e) {
            Log::error('MediaController@bulkMove failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to move media: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk delete assets
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset_ids' => 'required|array',
            'asset_ids.*' => 'integer',
        ]);

        try {
            $tenantId = $this->getTenantId($request);
            $count = $this->mediaService->bulkDelete($tenantId, $validated['asset_ids']);

            return response()->json([
                'success' => true,
                'message' => "{$count} file(s) deleted successfully.",
            ]);
        } catch (\Throwable $e) {
            Log::error('MediaController@bulkDelete failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete media: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Record asset access
     */
    public function recordAccess(Request $request, int $id): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $asset = $this->mediaService->recordAccess($tenantId, $id);

            return response()->json([
                'success' => true,
                'data' => $asset,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false], 404);
        }
    }
}

