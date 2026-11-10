<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaAssetReference;
use App\Models\MediaFolder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaStorageService
{
    /**
     * Default tenant quota: 5GB in bytes
     */
    public const DEFAULT_QUOTA_BYTES = 5368709120; // 5 * 1024 * 1024 * 1024

    /**
     * Default seed folders created for every tenant
     */
    public const DEFAULT_FOLDERS = [
        ['name' => 'Campaign Assets', 'color' => '#3B82F6'],
        ['name' => 'Social Media Posts', 'color' => '#10B981'],
        ['name' => 'Reels & Videos', 'color' => '#EC4899'],
        ['name' => 'Brand Assets', 'color' => '#8B5CF6'],
        ['name' => 'Client Assets', 'color' => '#F59E0B'],
        ['name' => 'Documents', 'color' => '#6B7280'],
    ];

    /**
     * Get overview stats for the tenant's media storage dashboard
     */
    public function getStorageStats(int $tenantId): array
    {
        $this->ensureDefaultFoldersExist($tenantId);

        $assets = MediaAsset::where('tenant_id', $tenantId);

        $totalFiles = (clone $assets)->count();
        $totalStorageUsed = (clone $assets)->sum('file_size');
        $availableStorage = max(0, self::DEFAULT_QUOTA_BYTES - $totalStorageUsed);

        $imagesCount = (clone $assets)->where('file_type', MediaAsset::TYPE_IMAGE)->count();
        $videosCount = (clone $assets)->where('file_type', MediaAsset::TYPE_VIDEO)->count();
        $documentsCount = (clone $assets)->where('file_type', MediaAsset::TYPE_DOCUMENT)->count();
        $audioCount = (clone $assets)->where('file_type', MediaAsset::TYPE_AUDIO)->count();

        $recentUploads = (clone $assets)
            ->with('folder')
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get();

        $recentAccessed = (clone $assets)
            ->with('folder')
            ->whereNotNull('last_accessed_at')
            ->orderBy('last_accessed_at', 'desc')
            ->limit(8)
            ->get();

        return [
            'total_files' => $totalFiles,
            'total_storage_used' => (int) $totalStorageUsed,
            'total_storage_quota' => self::DEFAULT_QUOTA_BYTES,
            'available_storage' => (int) $availableStorage,
            'usage_percentage' => round(($totalStorageUsed / self::DEFAULT_QUOTA_BYTES) * 100, 2),
            'images_count' => $imagesCount,
            'videos_count' => $videosCount,
            'documents_count' => $documentsCount,
            'audio_count' => $audioCount,
            'recent_uploads' => $recentUploads,
            'recent_accessed' => $recentAccessed,
        ];
    }

    /**
     * Ensure default folders exist for tenant
     */
    public function ensureDefaultFoldersExist(int $tenantId): void
    {
        $count = MediaFolder::where('tenant_id', $tenantId)->count();
        if ($count === 0) {
            foreach (self::DEFAULT_FOLDERS as $folder) {
                MediaFolder::create([
                    'tenant_id' => $tenantId,
                    'parent_id' => null,
                    'name' => $folder['name'],
                    'slug' => Str::slug($folder['name']),
                    'color' => $folder['color'],
                ]);
            }
        }
    }

    /**
     * Get folders with hierarchy
     */
    public function getFolders(int $tenantId)
    {
        $this->ensureDefaultFoldersExist($tenantId);

        return MediaFolder::where('tenant_id', $tenantId)
            ->withCount('assets')
            ->with(['children' => function ($q) {
                $q->withCount('assets');
            }])
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a new folder
     */
    public function createFolder(int $tenantId, array $data): MediaFolder
    {
        return MediaFolder::create([
            'tenant_id' => $tenantId,
            'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'color' => $data['color'] ?? '#6B7280',
        ]);
    }

    /**
     * Rename or update a folder
     */
    public function updateFolder(int $tenantId, int $folderId, array $data): MediaFolder
    {
        $folder = MediaFolder::where('tenant_id', $tenantId)->findOrFail($folderId);
        
        if (isset($data['name'])) {
            $folder->name = $data['name'];
            $folder->slug = Str::slug($data['name']);
        }
        if (isset($data['parent_id'])) {
            // Avoid circular nesting
            if ($data['parent_id'] != $folderId) {
                $folder->parent_id = $data['parent_id'];
            }
        }
        if (isset($data['color'])) {
            $folder->color = $data['color'];
        }

        $folder->save();
        return $folder;
    }

    /**
     * Delete a folder
     */
    public function deleteFolder(int $tenantId, int $folderId): void
    {
        $folder = MediaFolder::where('tenant_id', $tenantId)->findOrFail($folderId);

        // Move sub-assets to root (media_folder_id = null)
        MediaAsset::where('tenant_id', $tenantId)
            ->where('media_folder_id', $folderId)
            ->update(['media_folder_id' => null]);

        // Move sub-folders to parent
        MediaFolder::where('tenant_id', $tenantId)
            ->where('parent_id', $folderId)
            ->update(['parent_id' => $folder->parent_id]);

        $folder->delete();
    }

    /**
     * Get paginated media assets with filters
     */
    public function getAssets(int $tenantId, array $filters = [])
    {
        $query = MediaAsset::where('tenant_id', $tenantId)
            ->with(['folder', 'references']);

        if (!empty($filters['folder_id'])) {
            if ($filters['folder_id'] === 'root') {
                $query->whereNull('media_folder_id');
            } else {
                $query->where('media_folder_id', (int) $filters['folder_id']);
            }
        }

        if (!empty($filters['file_type']) && $filters['file_type'] !== 'all') {
            $query->where('file_type', $filters['file_type']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('original_name', 'like', $search)
                  ->orWhere('filename', 'like', $search);
            });
        }

        if (!empty($filters['tag'])) {
            $query->whereJsonContains('tags', $filters['tag']);
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['created_at', 'original_name', 'file_size', 'access_count'])) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 24)));
        return $query->paginate($perPage);
    }

    /**
     * Store an uploaded file into media library
     */
    public function storeUpload(int $tenantId, UploadedFile $file, ?int $folderId = null, ?int $userId = null, array $tags = []): MediaAsset
    {
        $fileSize = $file->getSize();

        // 1. Check storage quota
        $currentUsage = MediaAsset::where('tenant_id', $tenantId)->sum('file_size');
        if (($currentUsage + $fileSize) > self::DEFAULT_QUOTA_BYTES) {
            throw new \Exception('Storage quota exceeded (5GB limit). Please free up space to upload more files.');
        }

        // 2. Hash calculation for duplicate detection
        $fileHash = hash_file('sha256', $file->getRealPath());

        // Check for existing identical file in this tenant
        $existing = MediaAsset::where('tenant_id', $tenantId)
            ->where('file_hash', $fileHash)
            ->first();

        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream';
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $fileType = $this->determineFileType($mimeType, $extension);

        // If duplicate exists, we can create a record referencing the existing physical file or return existing
        if ($existing) {
            // Create a new asset instance referencing same storage or update access
            $existing->touch();
            $existing->increment('access_count');
            return $existing;
        }

        // 3. Generate safe unique filename
        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $filename = uniqid('asset_') . '_' . ($safeName ?: 'file') . '.' . $extension;

        // 4. Determine destination path: media/{tenant_id}/{folder_id}/
        $folderSegment = $folderId ? 'f_' . $folderId : 'root';
        $relativeDir = "media/{$tenantId}/{$folderSegment}";
        $disk = 'public';

        $storedPath = Storage::disk($disk)->putFileAs($relativeDir, $file, $filename);

        // 5. Build public URL
        $url = asset('storage/' . $storedPath);

        // 6. Extract dimensions & metadata if image
        $width = null;
        $height = null;
        $metadata = [];

        if ($fileType === MediaAsset::TYPE_IMAGE && in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            try {
                $imageSize = @getimagesize($file->getRealPath());
                if ($imageSize) {
                    $width = $imageSize[0] ?? null;
                    $height = $imageSize[1] ?? null;
                    $metadata['mime'] = $imageSize['mime'] ?? $mimeType;
                    $metadata['bits'] = $imageSize['bits'] ?? null;
                }
            } catch (\Throwable $e) {
                // Silently continue if image parsing fails
            }
        }

        // 7. Save MediaAsset record
        return MediaAsset::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'media_folder_id' => $folderId,
            'filename' => $filename,
            'original_name' => $originalName,
            'disk' => $disk,
            'file_path' => $storedPath,
            'url' => $url,
            'mime_type' => $mimeType,
            'file_type' => $fileType,
            'file_size' => $fileSize,
            'file_hash' => $fileHash,
            'width' => $width,
            'height' => $height,
            'metadata' => $metadata,
            'tags' => !empty($tags) ? array_values(array_unique($tags)) : null,
            'access_count' => 1,
            'last_accessed_at' => now(),
        ]);
    }

    /**
     * Determine file type categorization
     */
    public function determineFileType(string $mimeType, string $extension): string
    {
        if (str_starts_with($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'])) {
            return MediaAsset::TYPE_IMAGE;
        }

        if (str_starts_with($mimeType, 'video/') || in_array($extension, ['mp4', 'mov', 'webm', 'm4v', 'avi', 'mkv'])) {
            return MediaAsset::TYPE_VIDEO;
        }

        if (str_starts_with($mimeType, 'audio/') || in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'flac'])) {
            return MediaAsset::TYPE_AUDIO;
        }

        if (
            str_contains($mimeType, 'pdf') ||
            str_contains($mimeType, 'msword') ||
            str_contains($mimeType, 'officedocument') ||
            str_contains($mimeType, 'text') ||
            str_contains($mimeType, 'csv') ||
            in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'ppt', 'pptx'])
        ) {
            return MediaAsset::TYPE_DOCUMENT;
        }

        return MediaAsset::TYPE_OTHER;
    }

    /**
     * Update asset metadata (rename, tags, folder)
     */
    public function updateAsset(int $tenantId, int $assetId, array $data): MediaAsset
    {
        $asset = MediaAsset::where('tenant_id', $tenantId)->findOrFail($assetId);

        if (isset($data['original_name']) && !empty($data['original_name'])) {
            $asset->original_name = $data['original_name'];
        }

        if (array_key_exists('media_folder_id', $data)) {
            $asset->media_folder_id = $data['media_folder_id'] ? (int) $data['media_folder_id'] : null;
        }

        if (isset($data['tags']) && is_array($data['tags'])) {
            $asset->tags = array_values(array_unique($data['tags']));
        }

        $asset->save();
        return $asset;
    }

    /**
     * Soft delete asset (protects physical file if referenced elsewhere)
     */
    public function deleteAsset(int $tenantId, int $assetId): bool
    {
        $asset = MediaAsset::where('tenant_id', $tenantId)->findOrFail($assetId);
        
        // Soft delete ensures references in social posts and landing pages are not broken
        return (bool) $asset->delete();
    }

    /**
     * Bulk move assets to another folder
     */
    public function bulkMove(int $tenantId, array $assetIds, ?int $folderId): int
    {
        return MediaAsset::where('tenant_id', $tenantId)
            ->whereIn('id', $assetIds)
            ->update(['media_folder_id' => $folderId]);
    }

    /**
     * Bulk delete assets
     */
    public function bulkDelete(int $tenantId, array $assetIds): int
    {
        return MediaAsset::where('tenant_id', $tenantId)
            ->whereIn('id', $assetIds)
            ->delete();
    }

    /**
     * Record a cross-module reference (e.g. social post, landing page)
     */
    public function addReference(int $tenantId, int $assetId, string $referenceType, string $referenceId): MediaAssetReference
    {
        return MediaAssetReference::firstOrCreate([
            'tenant_id' => $tenantId,
            'media_asset_id' => $assetId,
            'reference_type' => $referenceType,
            'reference_id' => (string) $referenceId,
        ]);
    }

    /**
     * Mark asset accessed
     */
    public function recordAccess(int $tenantId, int $assetId): MediaAsset
    {
        $asset = MediaAsset::where('tenant_id', $tenantId)->findOrFail($assetId);
        $asset->increment('access_count');
        $asset->update(['last_accessed_at' => now()]);
        return $asset;
    }
}

