<?php

namespace App\Services\Builder;

use App\Models\BuilderForm;
use App\Models\BuilderPage;
use App\Models\BuilderSubmission;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Exception;

class SubmissionService
{
    public function __construct(
        protected CrmLeadSyncService $crmSync
    ) {}

    /**
     * Get paginated submissions for a tenant
     */
    public function getSubmissions(int $tenantId, array $filters = [])
    {
        $query = BuilderSubmission::forTenant($tenantId)
            ->with(['form:id,title,slug,form_type', 'page:id,title,slug', 'contact:id,name,email,phone']);

        if (!empty($filters['form_id']) && $filters['form_id'] !== 'all') {
            $query->where('form_id', $filters['form_id']);
        }

        if (!empty($filters['page_id']) && $filters['page_id'] !== 'all') {
            $query->where('page_id', $filters['page_id']);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('data', 'like', "%{$search}%")
                  ->orWhereHas('contact', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        return $query->latest()->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Process public submission from landing page or standalone form
     */
    public function processPublicSubmission(
        string $formSlug,
        array $submittedData,
        ?string $pageSlug = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $referer = null,
        array $utmParams = []
    ): array {
        // 1. Resolve Form and/or Page
        $form = null;
        $page = null;

        // Try to find by form slug first
        $form = BuilderForm::where('slug', $formSlug)->first();

        // If not found by slug, check if formSlug is numeric ID or submittedData has form_id
        if (!$form && is_numeric($formSlug)) {
            $form = BuilderForm::find((int) $formSlug);
        }
        if (!$form && !empty($submittedData['form_id'])) {
            $form = BuilderForm::find((int) $submittedData['form_id']);
        }

        // If pageSlug is provided, find page
        if (!empty($pageSlug)) {
            $page = BuilderPage::where('slug', $pageSlug)->first();
        }

        // If form still not found, check if $formSlug is actually a PAGE slug!
        if (!$form) {
            $candidatePage = BuilderPage::where('slug', $formSlug)->first();
            if ($candidatePage) {
                $page = $candidatePage;
                $pageSlug = $page->slug;

                // Check if the page has a connected form in its sections or forms relation
                if (!empty($submittedData['form_id'])) {
                    $form = BuilderForm::where('tenant_id', $page->tenant_id)->find($submittedData['form_id']);
                }

                if (!$form) {
                    $form = $page->forms()->first();
                }

                // If page still has no form record, auto-create / find default contact form for this page
                if (!$form) {
                    $form = BuilderForm::firstOrCreate(
                        [
                            'tenant_id' => $page->tenant_id,
                            'page_id' => $page->id,
                            'form_type' => BuilderForm::TYPE_CONTACT_FORM,
                        ],
                        [
                            'user_id' => $page->user_id,
                            'title' => ($page->title ?: 'Landing Page') . ' - Contact Form',
                            'slug' => ($page->slug ?: 'page-' . $page->id) . '-contact',
                            'status' => BuilderForm::STATUS_PUBLISHED,
                            'schema' => [
                                'fields' => [
                                    ['name' => 'name', 'label' => 'Full Name', 'type' => 'text', 'required' => true],
                                    ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true],
                                    ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'tel', 'required' => false],
                                    ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => false],
                                ],
                            ],
                            'settings' => [
                                'thank_you_message' => 'Thank you! Your inquiry has been received.',
                            ],
                        ]
                    );
                }
            }
        }

        // If still no form, throw clean exception
        if (!$form) {
            throw new Exception("Form or landing page with slug '{$formSlug}' not found or is currently unavailable.");
        }

        // Resolve Page if not resolved yet
        if (!$page && $form->page_id) {
            $page = $form->page;
        }

        // 3. Spam Detection & Honeypot Check
        // If hidden honeypot field is filled, silently discard spam
        if (!empty($submittedData['_hp_name']) || !empty($submittedData['website_hp'])) {
            Log::warning("[SubmissionService] Honeypot triggered for form {$formSlug} from IP {$ipAddress}");
            return [
                'success' => true,
                'message' => $form->settings['thank_you_message'] ?? 'Thank you for your submission.',
                'redirect_url' => $form->settings['redirect_url'] ?? null,
            ];
        }

        // Rate limiting check: 15 submissions per 5 minutes per IP
        $throttleKey = 'builder-sub:' . ($ipAddress ?: 'guest');
        if (RateLimiter::tooManyAttempts($throttleKey, 15)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw new Exception("Too many submissions. Please wait {$seconds} seconds before trying again.");
        }
        RateLimiter::hit($throttleKey, 300);

        // 4. Sanitize Input Data (strip tags, remove honeypot keys)
        $cleanData = [];
        foreach ($submittedData as $key => $val) {
            if (str_starts_with($key, '_') || str_ends_with($key, '_hp')) continue;
            if (is_array($val)) {
                $cleanData[$key] = array_map(fn($item) => is_string($item) ? strip_tags(trim($item)) : $item, $val);
            } elseif (is_string($val)) {
                $cleanData[$key] = strip_tags(trim($val));
            } else {
                $cleanData[$key] = $val;
            }
        }

        // 5. Survey-specific analytics calculation
        $surveyResults = null;
        if ($form->form_type === BuilderForm::TYPE_SURVEY) {
            $surveyResults = $this->calculateSurveyResults($cleanData);
        }

        // Extract UTM parameters if present
        if (empty($utmParams)) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $utm) {
                if (!empty($submittedData[$utm])) {
                    $utmParams[$utm] = strip_tags($submittedData[$utm]);
                }
            }
        }

        // 6. Synchronize into CRM Contacts
        $contact = $this->crmSync->syncSubmissionToContact(
            $form->tenant_id,
            $form,
            $cleanData,
            $page,
            [
                'ip_address' => $ipAddress,
                'referer' => $referer,
                'utm_params' => $utmParams,
            ]
        );

        // 7. Store Submission Record
        $submission = BuilderSubmission::create([
            'tenant_id' => $form->tenant_id,
            'form_id' => $form->id,
            'page_id' => $page?->id,
            'contact_id' => $contact?->id,
            'data' => $cleanData,
            'survey_results' => $surveyResults,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'referer' => $referer,
            'utm_params' => !empty($utmParams) ? $utmParams : null,
            'status' => $contact ? BuilderSubmission::STATUS_CONTACT_CREATED : BuilderSubmission::STATUS_NEW,
        ]);

        // 8. Increment Submission Counters
        $form->increment('submission_count');
        if ($page) {
            $page->increment('submission_count');
        }

        return [
            'success' => true,
            'message' => $form->settings['thank_you_message'] ?? 'Thank you! Your submission has been received.',
            'redirect_url' => $form->settings['redirect_url'] ?? null,
            'submission_id' => $submission->id,
        ];
    }

    /**
     * Compute survey scores, ratings, and analytics
     */
    protected function calculateSurveyResults(array $data): array
    {
        $ratings = [];
        $totalRating = 0;
        $ratingCount = 0;

        foreach ($data as $key => $val) {
            if (str_contains($key, 'rating') || str_contains($key, 'satisfaction') || str_contains($key, 'nps')) {
                if (is_numeric($val)) {
                    $score = (float) $val;
                    $ratings[$key] = $score;
                    $totalRating += $score;
                    $ratingCount++;
                }
            }
        }

        return [
            'ratings' => $ratings,
            'average_rating' => $ratingCount > 0 ? round($totalRating / $ratingCount, 2) : null,
            'fields_answered' => count($data),
            'completed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate CSV export string for a tenant's submissions
     */
    public function exportSubmissionsCsv(int $tenantId, array $filters = []): string
    {
        $submissions = BuilderSubmission::forTenant($tenantId)
            ->with(['form:id,title,form_type', 'page:id,title', 'contact:id,name,email,phone'])
            ->latest()
            ->take(2000)
            ->get();

        $output = fopen('php://temp', 'r+');

        // CSV Header
        fputcsv($output, [
            'Submission ID',
            'Form Title',
            'Form Type',
            'Source Page',
            'Contact Name',
            'Contact Email',
            'Contact Phone',
            'Submission Date',
            'IP Address',
            'Submitted Data (JSON)',
        ]);

        foreach ($submissions as $sub) {
            fputcsv($output, [
                $sub->id,
                $sub->form?->title ?? 'N/A',
                $sub->form?->form_type ?? 'N/A',
                $sub->page?->title ?? 'Standalone Form',
                $sub->contact?->name ?? 'N/A',
                $sub->contact?->email ?? 'N/A',
                $sub->contact?->phone ?? 'N/A',
                $sub->created_at->toDateTimeString(),
                $sub->ip_address ?? 'N/A',
                json_encode($sub->data),
            ]);
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }

    /**
     * Compute high-level dashboard metrics for the tenant
     */
    public function getDashboardMetrics(int $tenantId): array
    {
        $totalPages = BuilderPage::forTenant($tenantId)->count();
        $publishedPages = BuilderPage::forTenant($tenantId)->where('status', BuilderPage::STATUS_PUBLISHED)->count();

        $totalForms = BuilderForm::forTenant($tenantId)->count();
        $publishedForms = BuilderForm::forTenant($tenantId)->where('status', BuilderForm::STATUS_PUBLISHED)->count();

        $totalSubmissions = BuilderSubmission::forTenant($tenantId)->count();

        // Total leads generated (submissions linked to contact or status contact_created/updated)
        $leadsGenerated = BuilderSubmission::forTenant($tenantId)
            ->whereNotNull('contact_id')
            ->distinct('contact_id')
            ->count('contact_id');

        // Total page views across published assets
        $totalPageViews = BuilderPage::forTenant($tenantId)->sum('view_count');
        $totalFormViews = BuilderForm::forTenant($tenantId)->sum('view_count');
        $totalTraffic = $totalPageViews + $totalFormViews;

        $conversionRate = $totalTraffic > 0 
            ? round(($totalSubmissions / $totalTraffic) * 100, 2) 
            : 0;

        // Recent activity feed (latest 5 submissions and latest 5 updated pages)
        $recentSubmissions = BuilderSubmission::forTenant($tenantId)
            ->with(['form:id,title,form_type', 'contact:id,name,email'])
            ->latest()
            ->take(5)
            ->get();

        $recentPages = BuilderPage::forTenant($tenantId)
            ->select(['id', 'title', 'slug', 'status', 'view_count', 'submission_count', 'updated_at'])
            ->latest('updated_at')
            ->take(5)
            ->get();

        return [
            'total_pages' => $totalPages,
            'published_pages' => $publishedPages,
            'total_forms' => $totalForms,
            'published_forms' => $publishedForms,
            'total_submissions' => $totalSubmissions,
            'leads_generated' => $leadsGenerated,
            'total_views' => $totalTraffic,
            'conversion_rate' => $conversionRate,
            'recent_submissions' => $recentSubmissions,
            'recent_pages' => $recentPages,
        ];
    }
}

