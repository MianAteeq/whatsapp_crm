<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailDomainAuditLog;
use App\Models\EmailSendingDomain;
use App\Services\Email\MailtrapDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailSendingDomainController extends Controller
{
    public function __construct(
        protected MailtrapDomainService $domainService
    ) {}

    /**
     * List all sending domains for the authenticated tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $domains = EmailSendingDomain::where('tenant_id', $tenantId)
            ->latest('created_at')
            ->get();

        $verifiedCount = $domains->where('status', EmailSendingDomain::STATUS_VERIFIED)->count();
        $pendingCount = $domains->where('status', EmailSendingDomain::STATUS_PENDING)->count();

        return response()->json([
            'success' => true,
            'data' => $domains,
            'summary' => [
                'total' => $domains->count(),
                'verified_count' => $verifiedCount,
                'pending_count' => $pendingCount,
                'can_send' => $verifiedCount > 0,
            ],
        ]);
    }

    /**
     * Register a new sending domain.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $userId = (int) $request->user()->id;

        $validated = $request->validate([
            'domain' => 'required|string|max:191',
        ]);

        try {
            $domainRecord = $this->domainService->createDomain(
                $tenantId,
                $validated['domain'],
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => "Sending domain '{$domainRecord->domain}' added successfully. Please configure the DNS records with your registrar.",
                'data' => $domainRecord,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get specific domain details and its DNS records.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $domain = EmailSendingDomain::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $domain,
        ]);
    }

    /**
     * Trigger verification check with Mailtrap for a domain.
     */
    public function verify(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $userId = (int) $request->user()->id;

        $domain = EmailSendingDomain::where('tenant_id', $tenantId)->findOrFail($id);

        try {
            $result = $this->domainService->verifyDomain($domain, $userId);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => $result['domain'],
                'dns_verified' => $result['dns_verified'] ?? false,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => "Failed to check verification status: " . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Remove a sending domain.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $userId = (int) $request->user()->id;

        $domain = EmailSendingDomain::where('tenant_id', $tenantId)->findOrFail($id);

        $domainName = $domain->domain;
        $this->domainService->deleteDomain($domain, $userId);

        return response()->json([
            'success' => true,
            'message' => "Sending domain '{$domainName}' was removed successfully.",
        ]);
    }

    /**
     * Get audit log history for a sending domain.
     */
    public function domainLogs(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $domain = EmailSendingDomain::where('tenant_id', $tenantId)->findOrFail($id);

        $logs = EmailDomainAuditLog::with('user:id,name,email')
            ->where('tenant_id', $tenantId)
            ->where('domain_id', $id)
            ->latest()
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Admin view showing all sending domains across tenants without force verification.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        if (($user->role ?? '') !== 'admin' && ($user->is_admin ?? false) !== true) {
            // Check if user is tenant owner / admin
            if (($user->role ?? '') !== 'owner') {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Administrator access required.',
                ], 403);
            }
        }

        $query = EmailSendingDomain::with('tenant:id,name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('domain', 'like', $search)
                  ->orWhere('mailtrap_domain_id', 'like', $search);
            });
        }

        $domains = $query->latest('created_at')->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $domains->items(),
            'meta' => [
                'current_page' => $domains->currentPage(),
                'last_page' => $domains->lastPage(),
                'total' => $domains->total(),
            ],
        ]);
    }
}
