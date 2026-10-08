<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Contact;
use App\Models\EmailCampaignRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    protected function currentTenantId(): int
    {
        return (int) (auth()->user()?->tenant_id ?? abort(401, 'Unauthorized'));
    }

    /**
     * List all categories for current tenant with business, contact & email metrics
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->currentTenantId();

        $query = Category::where('tenant_id', $tenantId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $term = trim($request->search);
            $query->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', "%{$term}%")
                  ->orWhere('slug', 'LIKE', "%{$term}%");
            });
        }

        $categories = $query->orderBy('name')->get();

        $data = $categories->map(function (Category $category) use ($tenantId) {
            return $this->formatCategoryWithStats($category, $tenantId);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Store new category
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->currentTenantId();

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'slug' => 'nullable|string|max:191',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['name']);

        // Check uniqueness within tenant
        $exists = Category::where('tenant_id', $tenantId)->where('slug', $slug)->exists();
        if ($exists) {
            $slug .= '-' . Str::random(4);
        }

        $category = Category::create([
            'tenant_id' => $tenantId,
            'name' => trim($validated['name']),
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $this->formatCategoryWithStats($category, $tenantId),
        ], 201);
    }

    /**
     * Show single category with full statistics
     */
    public function show(int $id): JsonResponse
    {
        $tenantId = $this->currentTenantId();
        $category = Category::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->formatCategoryWithStats($category, $tenantId),
        ]);
    }

    /**
     * Update category
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->currentTenantId();
        $category = Category::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:191',
            'slug' => 'nullable|string|max:191',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        if (!empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['slug']);
        }

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $this->formatCategoryWithStats($category->fresh(), $tenantId),
        ]);
    }

    /**
     * Delete category
     */
    public function destroy(int $id): JsonResponse
    {
        $tenantId = $this->currentTenantId();
        $category = Category::where('tenant_id', $tenantId)->findOrFail($id);

        // Detach relations
        $category->businesses()->detach();
        $category->contacts()->detach();
        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Dedicated category email metrics (Requirement 12)
     */
    public function emailStats(int $id): JsonResponse
    {
        $tenantId = $this->currentTenantId();
        $category = Category::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->calculateEmailStats($category, $tenantId),
        ]);
    }

    /**
     * Helper to compute real stats from actual contacts, businesses & email_campaign_recipients
     */
    protected function formatCategoryWithStats(Category $category, int $tenantId): array
    {
        $stats = $this->calculateEmailStats($category, $tenantId);

        return [
            'id' => $category->id,
            'tenant_id' => $category->tenant_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'status' => $category->status,
            'created_at' => $category->created_at?->toISOString(),
            'updated_at' => $category->updated_at?->toISOString(),
            'metrics' => $stats,
        ];
    }

    /**
     * Calculate relational email statistics (Requirement 12)
     */
    protected function calculateEmailStats(Category $category, int $tenantId): array
    {
        // 1. Businesses count
        $businessesCount = DB::table('business_categories')
            ->join('businesses', 'businesses.id', '=', 'business_categories.business_id')
            ->where('businesses.tenant_id', $tenantId)
            ->where('business_categories.category_id', $category->id)
            ->count();

        // 2. Contact IDs belonging to this category
        $contactIds = DB::table('contact_categories')
            ->join('contacts', 'contacts.id', '=', 'contact_categories.contact_id')
            ->where('contacts.tenant_id', $tenantId)
            ->where('contact_categories.category_id', $category->id)
            ->pluck('contacts.id')
            ->all();

        $totalContacts = count($contactIds);

        // 3. Contacts with email
        $contactsWithEmail = 0;
        if (!empty($contactIds)) {
            $contactsWithEmail = DB::table('contacts')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $contactIds)
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->count();
        }

        // 4. Email campaign recipient activity (from actual recipient records, NOT scraper records!)
        $sentCount = 0;
        $deliveredCount = 0;
        $bouncedCount = 0;
        $failedCount = 0;
        $pendingCount = 0;
        $unsubscribedCount = 0;

        if (!empty($contactIds)) {
            $recipientsQuery = DB::table('email_campaign_recipients')
                ->where('tenant_id', $tenantId)
                ->whereIn('contact_id', $contactIds);

            $countsByStatus = (clone $recipientsQuery)
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all();

            $deliveredCount = (int) ($countsByStatus['delivered'] ?? 0);
            $bouncedCount = (int) ($countsByStatus['bounced'] ?? 0);
            $failedCount = (int) ($countsByStatus['failed'] ?? 0);
            $unsubscribedCount = (int) ($countsByStatus['unsubscribed'] ?? 0);
            $pendingCount = (int) (($countsByStatus['pending'] ?? 0) + ($countsByStatus['queued'] ?? 0) + ($countsByStatus['sending'] ?? 0));
            $sentOnlyCount = (int) ($countsByStatus['sent'] ?? 0);

            // Total sent attempts (sent + delivered + bounced + failed)
            $sentCount = $sentOnlyCount + $deliveredCount + $bouncedCount + $failedCount;
        }

        $deliveryRate = $sentCount > 0 ? round(($deliveredCount / $sentCount) * 100, 2) : 0.0;
        $bounceRate = $sentCount > 0 ? round(($bouncedCount / $sentCount) * 100, 2) : 0.0;

        return [
            'total_businesses' => $businessesCount,
            'total_contacts' => $totalContacts,
            'contacts_with_email' => $contactsWithEmail,
            'email_activity' => [
                'sent' => $sentCount,
                'delivered' => $deliveredCount,
                'bounced' => $bouncedCount,
                'failed' => $failedCount,
                'pending' => $pendingCount,
                'unsubscribed' => $unsubscribedCount,
                'delivery_rate' => $deliveryRate,
                'bounce_rate' => $bounceRate,
            ],
        ];
    }
}
