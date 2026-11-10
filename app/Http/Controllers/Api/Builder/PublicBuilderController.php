<?php

namespace App\Http\Controllers\Api\Builder;

use App\Http\Controllers\Controller;
use App\Models\BuilderPage;
use App\Models\BuilderForm;
use App\Services\Builder\SubmissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

class PublicBuilderController extends Controller
{
    public function __construct(
        protected SubmissionService $submissionService
    ) {}

    /**
     * Get landing page by slug (public endpoint, supports draft preview for authenticated users)
     */
    public function showPage(Request $request, string $slug): JsonResponse
    {
        $query = BuilderPage::where('slug', $slug);

        $isPreview = $request->boolean('preview') || $request->has('preview');
        $user = $request->user('sanctum');

        // Non-preview, non-authenticated requests must only see published pages
        if (!$isPreview && !$user) {
            $query->where('status', BuilderPage::STATUS_PUBLISHED);
        }

        $page = $query->with(['forms' => function ($q) use ($isPreview, $user) {
                if (!$isPreview && !$user) {
                    $q->where('status', BuilderForm::STATUS_PUBLISHED);
                }
            }])
            ->first();

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'Landing page not found or is currently unpublished.',
            ], 404);
        }

        // Only increment view count for real published visits
        if ($page->status === BuilderPage::STATUS_PUBLISHED) {
            $page->increment('view_count');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'description' => $page->description,
                'category' => $page->category,
                'status' => $page->status,
                'theme' => $page->theme,
                'sections' => $page->sections,
                'seo_settings' => $page->seo_settings,
                'published_at' => $page->published_at,
                'forms' => $page->forms,
            ],
        ]);
    }

    /**
     * Get standalone form or survey by slug (public endpoint, supports draft preview)
     */
    public function showForm(Request $request, string $slug): JsonResponse
    {
        $query = BuilderForm::where('slug', $slug);

        $isPreview = $request->boolean('preview') || $request->has('preview');
        $user = $request->user('sanctum');

        if (!$isPreview && !$user) {
            $query->where('status', BuilderForm::STATUS_PUBLISHED);
        }

        $form = $query->first();

        if (!$form) {
            return response()->json([
                'success' => false,
                'message' => 'Form not found or is currently unpublished.',
            ], 404);
        }

        if ($form->status === BuilderForm::STATUS_PUBLISHED) {
            $form->increment('view_count');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $form->id,
                'title' => $form->title,
                'slug' => $form->slug,
                'form_type' => $form->form_type,
                'status' => $form->status,
                'schema' => $form->schema,
                'settings' => $form->settings,
            ],
        ]);
    }

    /**
     * Submit public form or survey
     */
    public function submit(Request $request, string $slug): JsonResponse
    {
        $submittedData = $request->all();
        $pageSlug = $request->header('X-Page-Slug') ?: $request->input('_page_slug');
        $ipAddress = $request->ip();
        $userAgent = $request->header('User-Agent');
        $referer = $request->header('Referer');

        $utmParams = [
            'utm_source' => $request->input('utm_source'),
            'utm_medium' => $request->input('utm_medium'),
            'utm_campaign' => $request->input('utm_campaign'),
            'utm_term' => $request->input('utm_term'),
            'utm_content' => $request->input('utm_content'),
        ];
        $utmParams = array_filter($utmParams);

        try {
            $result = $this->submissionService->processPublicSubmission(
                $slug,
                $submittedData,
                $pageSlug,
                $ipAddress,
                $userAgent,
                $referer,
                $utmParams
            );

            return response()->json([
                'success' => true,
                'message' => $result['thank_you_message'] ?? 'Submission received successfully.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
