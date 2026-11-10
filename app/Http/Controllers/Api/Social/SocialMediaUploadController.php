<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class SocialMediaUploadController extends Controller
{
    /**
     * Upload an image or video for social post composition
     */
    public function upload(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,mp4,mov',
                'max:102400', // 100MB max
            ],
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType();
        $isImage = str_starts_with($mime, 'image/');
        $isVideo = str_starts_with($mime, 'video/');
        $mediaType = $isImage ? 'image' : ($isVideo ? 'video' : 'other');

        $originalName = $file->getClientOriginalName();
        $ext = $file->getClientOriginalExtension();
        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '_' . Str::random(8) . '.' . $ext;

        $directory = "social/{$tenantId}/" . date('Y/m');
        $storedPath = $file->storeAs($directory, $safeName, 'public');

        // Build fully-qualified public URL accessible by Meta crawlers
        $appUrl = config('app.url');
        if (!empty($appUrl) && !str_contains($appUrl, 'localhost') && !str_contains($appUrl, '127.0.0.1')) {
            $publicUrl = rtrim($appUrl, '/') . "/storage/{$storedPath}";
        } else {
            $publicUrl = asset("storage/{$storedPath}");
        }

        $width = null;
        $height = null;
        $aspectRatio = null;

        if ($isImage) {
            $imageInfo = @getimagesize($file->getRealPath());
            if ($imageInfo) {
                $width = (int) $imageInfo[0];
                $height = (int) $imageInfo[1];
                if ($height > 0) {
                    $ratio = round($width / $height, 2);
                    $aspectRatio = "{$ratio}:1";
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'url' => $publicUrl,
                'stream_url' => url('/api/v1/social/media/stream?path=' . urlencode($storedPath)),
                'file_path' => $storedPath,
                'filename' => $originalName,
                'media_type' => $mediaType,
                'mime_type' => $mime,
                'file_size' => $file->getSize(),
                'width' => $width,
                'height' => $height,
                'aspect_ratio' => $aspectRatio,
            ],
        ]);
    }

    /**
     * Stream a social media file with full CORS and byte-range headers for seeking & canvas snapshots
     */
    public function stream(Request $request)
    {
        $path = $request->query('path');
        if (empty($path)) {
            $url = $request->query('url');
            if ($url) {
                $parsed = parse_url($url, PHP_URL_PATH);
                if ($parsed && str_contains($parsed, '/storage/')) {
                    $path = substr($parsed, strpos($parsed, '/storage/') + 9);
                }
            }
        }

        if (!empty($path)) {
            $path = ltrim($path, "/\\");
            if (str_starts_with($path, 'storage/')) {
                $path = substr($path, 8);
            }
        }

        if (empty($path) || !Storage::disk('public')->exists($path)) {
            return response()->json(['error' => 'File not found', 'path' => $path], 404);
        }

        $fullPath = Storage::disk('public')->path($path);
        $mime = Storage::disk('public')->mimeType($path) ?: 'application/octet-stream';

        $headers = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers' => 'Range, Content-Type, Accept, Origin',
            'Access-Control-Expose-Headers' => 'Content-Length, Content-Range, Accept-Ranges',
            'Accept-Ranges' => 'bytes',
            'Content-Type' => $mime,
        ];

        return response()->file($fullPath, $headers);
    }
}

