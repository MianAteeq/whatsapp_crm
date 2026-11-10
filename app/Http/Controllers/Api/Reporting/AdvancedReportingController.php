<?php

namespace App\Http\Controllers\Api\Reporting;

use App\Http\Controllers\Controller;
use App\Services\Reporting\AdvancedReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class AdvancedReportingController extends Controller
{
    public function __construct(
        protected AdvancedReportingService $reportingService
    ) {}

    protected function getTenantId(Request $request): int
    {
        return (int) ($request->user()?->tenant_id ?? 1);
    }

    /**
     * Get overall marketing dashboard analytics
     */
    public function getOverview(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $filters = $request->only(['period', 'start_date', 'end_date']);
            $overview = $this->reportingService->getMarketingOverview($tenantId, $filters);

            return response()->json([
                'success' => true,
                'data' => $overview,
            ]);
        } catch (\Throwable $e) {
            Log::error('AdvancedReportingController@getOverview failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load marketing overview: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get advertising and campaign performance
     */
    public function getAdvertising(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $filters = $request->only(['period', 'start_date', 'end_date', 'platform']);
            $ads = $this->reportingService->getAdvertisingPerformance($tenantId, $filters);

            return response()->json([
                'success' => true,
                'data' => $ads,
            ]);
        } catch (\Throwable $e) {
            Log::error('AdvancedReportingController@getAdvertising failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load advertising performance: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get social media reach and engagement analytics
     */
    public function getSocial(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $filters = $request->only(['period', 'start_date', 'end_date', 'platform']);
            $social = $this->reportingService->getSocialAnalytics($tenantId, $filters);

            return response()->json([
                'success' => true,
                'data' => $social,
            ]);
        } catch (\Throwable $e) {
            Log::error('AdvancedReportingController@getSocial failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load social analytics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get lead generation and attribution metrics
     */
    public function getLeads(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $filters = $request->only(['period', 'start_date', 'end_date', 'source']);
            $leads = $this->reportingService->getLeadGenerationReport($tenantId, $filters);

            return response()->json([
                'success' => true,
                'data' => $leads,
            ]);
        } catch (\Throwable $e) {
            Log::error('AdvancedReportingController@getLeads failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load lead reports: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get team members response and conversion metrics
     */
    public function getTeam(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $filters = $request->only(['period', 'start_date', 'end_date']);
            $team = $this->reportingService->getTeamPerformanceReport($tenantId, $filters);

            return response()->json([
                'success' => true,
                'data' => $team,
            ]);
        } catch (\Throwable $e) {
            Log::error('AdvancedReportingController@getTeam failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load team performance: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get saved reports
     */
    public function getSavedReports(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $reports = $this->reportingService->getSavedReports($tenantId);

            return response()->json([
                'success' => true,
                'data' => $reports,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Save custom report
     */
    public function saveReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'report_type' => 'required|string|in:overview,ads,social,leads,team',
            'filters' => 'nullable|array',
            'metrics' => 'nullable|array',
            'schedule' => 'nullable|array',
        ]);

        try {
            $tenantId = $this->getTenantId($request);
            $userId = (int) ($request->user()?->id ?? 1);
            $report = $this->reportingService->saveReport($tenantId, $userId, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Custom report configuration saved.',
                'data' => $report,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete saved report
     */
    public function deleteSavedReport(Request $request, int $id): JsonResponse
    {
        try {
            $tenantId = $this->getTenantId($request);
            $this->reportingService->deleteSavedReport($tenantId, $id);

            return response()->json([
                'success' => true,
                'message' => 'Report deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Export report data as CSV
     */
    public function exportCsv(Request $request): Response
    {
        $tenantId = $this->getTenantId($request);
        $type = $request->input('type', 'overview');
        $filters = $request->only(['period', 'start_date', 'end_date']);

        $csvContent = $this->reportingService->exportReportCsv($tenantId, $type, $filters);
        $filename = "analytics_{$type}_" . date('Ymd_His') . ".csv";

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}

