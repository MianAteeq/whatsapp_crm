<?php

namespace App\Http\Controllers\Api\Builder;

use App\Http\Controllers\Controller;
use App\Models\BuilderSubmission;
use App\Services\Builder\SubmissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SubmissionController extends Controller
{
    public function __construct(
        protected SubmissionService $submissionService
    ) {}

    /**
     * List submissions with pagination & filters
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $filters = $request->only(['form_id', 'page_id', 'status', 'search', 'date_from', 'date_to']);

        $submissions = $this->submissionService->getSubmissions($tenantId, $filters);

        return response()->json([
            'success' => true,
            'data' => $submissions,
        ]);
    }

    /**
     * Show single submission detail
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $submission = BuilderSubmission::where('tenant_id', $tenantId)
            ->with(['form', 'page', 'contact'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $submission,
        ]);
    }

    /**
     * Delete a submission
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $submission = BuilderSubmission::where('tenant_id', $tenantId)->findOrFail($id);
        $submission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Submission deleted successfully.',
        ]);
    }

    /**
     * Export submissions to CSV
     */
    public function export(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $csv = $this->submissionService->exportSubmissionsCsv($tenantId, $request->all());

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="submissions_export_' . date('Y-m-d_His') . '.csv"',
        ]);
    }

    /**
     * Get Dashboard high-level metrics
     */
    public function dashboardMetrics(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $metrics = $this->submissionService->getDashboardMetrics($tenantId);

        return response()->json([
            'success' => true,
            'data' => $metrics,
        ]);
    }
}
