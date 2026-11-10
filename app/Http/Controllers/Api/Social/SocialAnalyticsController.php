<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Services\Social\SocialAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocialAnalyticsController extends Controller
{
    public function __construct(
        protected SocialAnalyticsService $analyticsService
    ) {}

    /**
     * Get aggregate analytics summary
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $filters = [
            'period' => $request->input('period', '30d'),
            'platform' => $request->input('platform'),
            'account_id' => $request->input('account_id'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
        ];

        if ($request->boolean('sync')) {
            $this->analyticsService->syncLiveMetrics($tenantId);
        }

        $data = $this->analyticsService->getAnalytics($tenantId, $filters);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Manually trigger live metrics sync from Meta Graph API
     */
    public function sync(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $result = $this->analyticsService->syncLiveMetrics($tenantId);

        return response()->json([
            'success' => true,
            'message' => 'Live engagement metrics synced successfully from Meta.',
            'data' => $result,
        ]);
    }
}

