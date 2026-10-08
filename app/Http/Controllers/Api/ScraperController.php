<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessScraperSearchJob;
use App\Models\ScrapedBusiness;
use App\Models\ScraperSearch;
use App\Services\Scraper\ScraperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScraperController extends Controller
{
    public function __construct(
        protected ScraperService $scraperService
    ) {}

    protected function currentUser()
    {
        return Auth::user() ?? abort(response()->json(['message' => 'Unauthorized'], 401));
    }

    protected function currentTenantId(): int
    {
        return (int) $this->currentUser()->tenant_id;
    }

    /**
     * Get scraper feature status, configuration, and stats
     */
    public function status(): JsonResponse
    {
        $tenantId = $this->currentTenantId();
        $status = $this->scraperService->getStatus();

        $stats = [
            'total_searches' => ScraperSearch::where('tenant_id', $tenantId)->count(),
            'total_leads_scraped' => ScrapedBusiness::where('tenant_id', $tenantId)->count(),
            'total_emails_discovered' => ScrapedBusiness::where('tenant_id', $tenantId)->whereNotNull('email')->count(),
            'total_imported_crm' => ScrapedBusiness::where('tenant_id', $tenantId)->where('is_imported_to_crm', true)->count(),
            'total_unimported_crm' => ScrapedBusiness::where('tenant_id', $tenantId)->where(function ($q) {
                $q->whereNull('is_imported_to_crm')->orWhere('is_imported_to_crm', false);
            })->count(),
        ];

        return response()->json([
            'success' => true,
            'status' => $status,
            'stats' => $stats,
        ]);
    }

    /**
     * List searches for tenant
     */
    public function searches(Request $request): JsonResponse
    {
        $tenantId = $this->currentTenantId();

        $searches = ScraperSearch::with('category:id,name,slug')
            ->where('tenant_id', $tenantId)
            ->latest()
            ->paginate($request->input('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => $searches,
        ]);
    }

    /**
     * Create and start a new scraper search
     */
    public function storeSearch(Request $request): JsonResponse
    {
        $request->validate([
            'keyword' => 'required|string|max:255',
            'industry' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'category_id' => 'nullable|integer',
            'location' => 'required|string|max:255',
            'radius' => 'nullable|integer|min:1|max:500000',
            'lead_volume' => 'nullable|integer|min:1|max:10000',
            'max_results' => 'nullable|integer|min:1|max:10000',
            'search_mode' => 'nullable|string|in:standard,deep',
            'options' => 'nullable|array',
            'immediate' => 'nullable|boolean',
        ]);

        $tenantId = $this->currentTenantId();
        $userId = (int) $this->currentUser()->id;

        $leadVolume = $request->input('lead_volume') ?? $request->input('max_results', 50);
        $searchMode = $request->input('search_mode') ?? ($request->input('options.search_mode') ?? 'standard');

        $search = $this->scraperService->createSearch($tenantId, $userId, [
            'keyword' => $request->keyword,
            'industry' => $request->industry,
            'category' => $request->category,
            'category_id' => $request->category_id,
            'location' => $request->location,
            'radius' => $request->radius,
            'lead_volume' => (int) $leadVolume,
            'max_results' => (int) $leadVolume,
            'search_mode' => $searchMode,
            'options' => $request->options,
        ]);

        // If immediate execution requested (e.g. sync demo / local dev)
        if ($request->boolean('immediate')) {
            try {
                $this->scraperService->processSearch($search);
                $search->refresh();
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Search failed: ' . $e->getMessage(),
                    'data' => $search,
                ], 500);
            }
        } else {
            // Dispatch to background queue
            ProcessScraperSearchJob::dispatch($search->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Scraper search initiated successfully',
            'data' => $search,
        ], 201);
    }

    /**
     * Get search details and real-time progress counters
     */
    public function getSearch(int $id): JsonResponse
    {
        $tenantId = $this->currentTenantId();

        $search = ScraperSearch::with('category:id,name,slug')
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $search,
        ]);
    }

    /**
     * Run or resume search processing synchronously (helpful for UI step-progress)
     */
    public function processNow(int $id): JsonResponse
    {
        $tenantId = $this->currentTenantId();

        $search = ScraperSearch::where('tenant_id', $tenantId)
            ->findOrFail($id);

        if ($search->status === 'completed') {
            return response()->json([
                'success' => true,
                'message' => 'Search already completed',
                'data' => $search,
            ]);
        }

        try {
            $this->scraperService->processSearch($search);
            $search->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Search processing completed',
                'data' => $search,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Processing failed: ' . $e->getMessage(),
                'data' => $search,
            ], 500);
        }
    }

    /**
     * Delete a search and all its scraped results
     */
    public function deleteSearch(int $id): JsonResponse
    {
        $tenantId = $this->currentTenantId();

        $search = ScraperSearch::where('tenant_id', $tenantId)
            ->findOrFail($id);

        $search->delete();

        return response()->json([
            'success' => true,
            'message' => 'Search and its associated leads deleted successfully',
        ]);
    }

    /**
     * List scraped businesses with filters and pagination
     */
    public function results(Request $request): JsonResponse
    {
        $tenantId = $this->currentTenantId();

        $query = ScrapedBusiness::with('emails')
            ->where('tenant_id', $tenantId);

        // Filter by search_id
        if ($request->filled('search_id')) {
            $query->where('search_id', $request->search_id);
        }

        // Filter by category_id or category string (Requirement 6)
        if ($request->filled('category_id')) {
            $catId = (int) $request->category_id;
            $catName = \App\Models\Category::where('tenant_id', $tenantId)->where('id', $catId)->value('name');
            $query->where(function ($q) use ($catId, $catName) {
                $q->whereHas('search', function ($sq) use ($catId) {
                    $sq->where('category_id', $catId);
                });
                if (!empty($catName)) {
                    $q->orWhere('category', 'LIKE', "%{$catName}%");
                }
            });
        } elseif ($request->filled('category')) {
            $catName = trim($request->category);
            $query->where('category', 'LIKE', "%{$catName}%");
        }

        // Filter tabs: All, Not in CRM, In CRM, Email Found, No Email, Website Found, No Website, Duplicates Removed, Failed
        $tab = $request->input('tab', $request->input('status', 'all'));
        if ($tab === 'not_in_crm') {
            $query->where(function ($q) {
                $q->whereNull('is_imported_to_crm')->orWhere('is_imported_to_crm', false);
            });
        } elseif ($tab === 'in_crm') {
            $query->where('is_imported_to_crm', true);
        } elseif ($tab === 'email_found') {
            $query->whereNotNull('email')->where('email', '!=', '');
        } elseif ($tab === 'no_email') {
            $query->where(function ($q) {
                $q->whereNull('email')->orWhere('email', '');
            })->where('status', '!=', 'failed');
        } elseif ($tab === 'website_found' || $tab === 'has_website') {
            $query->whereNotNull('website')->where('website', '!=', '');
        } elseif ($tab === 'no_website') {
            $query->where(function ($q) {
                $q->whereNull('website')->orWhere('website', '');
            });
        } elseif ($tab === 'failed') {
            $query->where('status', 'failed');
        } elseif ($tab === 'duplicates_removed') {
            $query->where('status', 'duplicate');
        } elseif ($tab !== 'all' && $request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by has email
        if ($request->boolean('has_email')) {
            $query->whereNotNull('email')->where('email', '!=', '');
        }

        // Keyword search in name, email, phone, category, address, website
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('business_name', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%")
                  ->orWhere('phone', 'like', "%{$term}%")
                  ->orWhere('category', 'like', "%{$term}%")
                  ->orWhere('address', 'like', "%{$term}%")
                  ->orWhere('website', 'like', "%{$term}%");
            });
        }

        $results = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json($results);
    }

    /**
     * Get single scraped business details
     */
    public function showResult(int $id): JsonResponse
    {
        $tenantId = $this->currentTenantId();

        $business = ScrapedBusiness::with('emails')
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $business,
        ]);
    }

    /**
     * Delete a single scraped business
     */
    public function deleteResult(int $id): JsonResponse
    {
        $tenantId = $this->currentTenantId();

        $business = ScrapedBusiness::where('tenant_id', $tenantId)
            ->findOrFail($id);

        $business->delete();

        return response()->json([
            'success' => true,
            'message' => 'Business record deleted successfully',
        ]);
    }

    /**
     * Batch delete selected scraped businesses
     */
    public function batchDeleteResults(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $tenantId = $this->currentTenantId();
        $ids = $request->ids;

        $deletedCount = ScrapedBusiness::where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => "Successfully deleted {$deletedCount} business records",
            'deleted_count' => $deletedCount,
        ]);
    }

    /**
     * Import selected scraped businesses or all search results into CRM Leads / Contacts
     */
    public function importToCrm(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'import_all' => 'nullable|boolean',
            'search_id' => 'nullable|integer',
            'tab' => 'nullable|string',
            'search' => 'nullable|string',
            'unimported_only' => 'nullable|boolean',
        ]);

        $tenantId = $this->currentTenantId();
        $userId = (int) $this->currentUser()->id;
        $ids = $request->filled('ids') ? array_map('intval', $request->ids) : [];
        $searchId = $request->filled('search_id') ? (int) $request->search_id : null;
        $filters = [
            'tab' => $request->input('tab'),
            'search' => $request->input('search'),
            'unimported_only' => $request->boolean('unimported_only'),
        ];

        if (empty($ids) && !$request->boolean('import_all')) {
            return response()->json([
                'success' => false,
                'message' => 'Please select businesses to import or specify import_all.',
            ], 422);
        }

        try {
            $summary = $this->scraperService->importToCrm($tenantId, $userId, $ids, $searchId, $filters);

            $msg = "Import evaluated {$summary['total']} businesses: {$summary['created']} new contacts created, {$summary['enriched']} existing contacts enriched, {$summary['already_up_to_date']} already up-to-date.";
            if (!empty($summary['errors'])) {
                $msg .= " (" . count($summary['errors']) . " issues occurred)";
            }

            return response()->json([
                'success' => true,
                'message' => $msg,
                'data' => $summary,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Targeted enrichment for a single business (e.g. missing email)
     */
    public function enrichLead(int $id): JsonResponse
    {
        $tenantId = $this->currentTenantId();
        $userId = (int) $this->currentUser()->id;

        try {
            $result = $this->scraperService->enrichLead($tenantId, $userId, $id);
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $result['business'],
                'enriched_fields' => $result['enriched_fields'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to enrich lead: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Bulk targeted enrichment for multiple selected businesses
     */
    public function bulkEnrichLeads(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $tenantId = $this->currentTenantId();
        $userId = (int) $this->currentUser()->id;
        $ids = array_map('intval', $request->ids);

        try {
            $result = $this->scraperService->bulkEnrichLeads($tenantId, $userId, $ids);
            return response()->json([
                'success' => true,
                'message' => "Bulk enrichment complete: {$result['enriched_count']} enriched, {$result['still_missing_count']} still missing info, {$result['failed_count']} failed.",
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk enrichment failed: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get enrichment audit trail logs for a lead
     */
    public function getEnrichmentLogs(int $id): JsonResponse
    {
        $tenantId = $this->currentTenantId();
        $biz = ScrapedBusiness::where('tenant_id', $tenantId)->findOrFail($id);

        $logs = \App\Models\ContactEnrichmentLog::where('tenant_id', $tenantId)
            ->where(function ($q) use ($biz) {
                $q->where('scraped_business_id', $biz->id);
                if ($biz->crm_contact_id) {
                    $q->orWhere('contact_id', $biz->crm_contact_id);
                }
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * Export scraped results as CSV
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $tenantId = $this->currentTenantId();

        $query = ScrapedBusiness::where('tenant_id', $tenantId);

        if ($request->filled('search_id')) {
            $query->where('search_id', $request->search_id);
        }

        $tab = $request->input('tab', $request->input('status', 'all'));
        if ($tab === 'email_found') {
            $query->whereNotNull('email')->where('email', '!=', '');
        } elseif ($tab === 'no_email') {
            $query->where(function ($q) {
                $q->whereNull('email')->orWhere('email', '');
            })->where('status', '!=', 'failed');
        } elseif ($tab === 'website_found' || $tab === 'has_website') {
            $query->whereNotNull('website')->where('website', '!=', '');
        } elseif ($tab === 'no_website') {
            $query->where(function ($q) {
                $q->whereNull('website')->orWhere('website', '');
            });
        } elseif ($tab === 'failed') {
            $query->where('status', 'failed');
        } elseif ($tab !== 'all' && $request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->boolean('has_email')) {
            $query->whereNotNull('email')->where('email', '!=', '');
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('business_name', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%")
                  ->orWhere('phone', 'like', "%{$term}%")
                  ->orWhere('category', 'like', "%{$term}%")
                  ->orWhere('address', 'like', "%{$term}%");
            });
        }

        $filename = 'scraped_businesses_' . date('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // Exact requested CSV columns without internal database IDs
            fputcsv($handle, [
                'Business Name',
                'Email',
                'Website',
                'Phone',
                'Category',
                'Address',
                'Google Maps URL',
                'Email Source',
            ]);

            $query->chunk(500, function ($businesses) use ($handle) {
                foreach ($businesses as $b) {
                    fputcsv($handle, [
                        $b->business_name,
                        $b->email ?? '',
                        $b->website ?? '',
                        $b->phone ?? '',
                        $b->category ?? '',
                        $b->address ?? '',
                        $b->google_maps_url ?? '',
                        $b->email_source ?? ($b->email ? 'Website' : 'N/A'),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Category-level scraper & email statistics (Requirement 19)
     */
    public function categoryStats(Request $request): JsonResponse
    {
        $tenantId = $this->currentTenantId();
        $categoryId = $request->input('category_id');

        $category = null;
        if ($categoryId) {
            $category = \App\Models\Category::where('tenant_id', $tenantId)->find($categoryId);
        }

        $categoryName = $category?->name;

        // Searches matching this category
        $searchIds = ScraperSearch::where('tenant_id', $tenantId)
            ->when($categoryId, fn($q) => $q->where('category_id', $categoryId))
            ->pluck('id');

        // Scraped businesses query
        $bizQuery = ScrapedBusiness::where('tenant_id', $tenantId);
        if ($categoryId || $categoryName) {
            $bizQuery->where(function ($q) use ($searchIds, $categoryName) {
                if ($searchIds->isNotEmpty()) {
                    $q->whereIn('search_id', $searchIds);
                }
                if ($categoryName) {
                    $q->orWhere('category', 'LIKE', "%{$categoryName}%");
                }
            });
        }

        $businessesFound = (clone $bizQuery)->count();
        $emailsFound = (clone $bizQuery)->whereNotNull('email')->where('email', '!=', '')->count();
        $phonesFound = (clone $bizQuery)->whereNotNull('phone')->where('phone', '!=', '')->count();
        $websitesFound = (clone $bizQuery)->whereNotNull('website')->where('website', '!=', '')->count();

        // Associated Contacts
        $contactIds = DB::table('contact_categories')
            ->when($categoryId, fn($q) => $q->where('category_id', $categoryId))
            ->pluck('contact_id');

        if ($contactIds->isEmpty() && $categoryId) {
            $contactIds = DB::table('scrape_results')
                ->where('tenant_id', $tenantId)
                ->where('category_id', $categoryId)
                ->whereNotNull('contact_id')
                ->pluck('contact_id');
        } elseif (!$categoryId) {
            $contactIds = Contact::where('tenant_id', $tenantId)->pluck('id');
        }

        $contactsFound = Contact::where('tenant_id', $tenantId)
            ->whereIn('id', $contactIds)
            ->count();

        // Email campaign recipient metrics for these contacts
        $previouslyEmailed = 0;
        $delivered = 0;
        $bounced = 0;
        $neverEmailed = $contactsFound;

        if ($contactIds->isNotEmpty()) {
            $previouslyEmailed = DB::table('email_campaign_recipients')
                ->where('tenant_id', $tenantId)
                ->whereIn('contact_id', $contactIds)
                ->whereIn('status', ['sent', 'delivered', 'bounced', 'failed'])
                ->distinct('contact_id')
                ->count('contact_id');

            $neverEmailed = max(0, $contactsFound - $previouslyEmailed);

            $delivered = DB::table('email_campaign_recipients')
                ->where('tenant_id', $tenantId)
                ->whereIn('contact_id', $contactIds)
                ->where('status', 'delivered')
                ->count();

            $bounced = DB::table('email_campaign_recipients')
                ->where('tenant_id', $tenantId)
                ->whereIn('contact_id', $contactIds)
                ->where('status', 'bounced')
                ->count();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'category_id' => $categoryId,
                'category_name' => $categoryName ?? 'All Categories',
                'businesses' => $businessesFound,
                'contacts' => $contactsFound,
                'emails' => $emailsFound,
                'phones' => $phonesFound,
                'websites' => $websitesFound,
                'never_emailed' => $neverEmailed,
                'previously_emailed' => $previouslyEmailed,
                'delivered' => $delivered,
                'bounced' => $bounced,
            ],
        ]);
    }
}
