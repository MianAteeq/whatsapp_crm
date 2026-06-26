<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Campaign;
use App\Models\WhatsappMessageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SuperAdminController extends Controller
{
    /**
     * Get global SaaS analytics and statistics.
     */
    public function dashboardStats()
    {
        $totalTenants = Tenant::count();
        $totalUsers = User::count();
        
        $totalCampaigns = 0;
        try {
            $totalCampaigns = Campaign::count();
        } catch (\Exception $e) {}

        $totalMessages = 0;
        try {
            $totalMessages = WhatsappMessageLog::count();
        } catch (\Exception $e) {}

        // Plan distribution
        $planStats = Tenant::select('plan', DB::raw('count(*) as count'))
            ->groupBy('plan')
            ->get();

        // Recent tenants
        $recentTenants = Tenant::latest()
            ->take(5)
            ->get()
            ->map(function ($tenant) {
                // Try to find owner user details
                $owner = User::find($tenant->owner_id);
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'plan' => $tenant->plan,
                    'created_at' => $tenant->created_at,
                    'owner_name' => $owner ? $owner->name : 'N/A',
                    'owner_email' => $owner ? $owner->email : 'N/A',
                ];
            });

        // Recent users
        $recentUsers = User::with('tenant')
            ->latest()
            ->take(5)
            ->get();

        // Weekly transmission analytics (past 7 days)
        $weeklyTransmission = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dayName = $date->format('D'); // E.g., "Mon"
            
            $broadcasts = \App\Models\Message::where('direction', 'outgoing')
                ->whereDate('created_at', $date->toDateString())
                ->count();
                
            $inbound = \App\Models\Message::where('direction', 'incoming')
                ->whereDate('created_at', $date->toDateString())
                ->count();
                
            $weeklyTransmission[] = [
                'name' => $dayName,
                'Broadcasts' => $broadcasts,
                'Inbound' => $inbound
            ];
        }

        return response()->json([
            'success' => true,
            'stats' => [
                'total_tenants' => $totalTenants,
                'total_users' => $totalUsers,
                'total_campaigns' => $totalCampaigns,
                'total_messages' => $totalMessages,
            ],
            'plan_distribution' => $planStats,
            'recent_tenants' => $recentTenants,
            'recent_users' => $recentUsers,
            'weekly_transmission' => $weeklyTransmission
        ]);
    }

    /**
     * List all tenants (workspaces) with owner and user counts.
     */
    public function tenantsIndex(Request $request)
    {
        $query = Tenant::query();

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where('name', 'like', $search);
        }

        $tenants = $query->latest()->paginate(20);

        // Map owner names and user counts
        $items = collect($tenants->items())->map(function ($tenant) {
            $owner = User::find($tenant->owner_id);
            $userCount = User::where('tenant_id', $tenant->id)->count();
            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'plan' => $tenant->plan,
                'created_at' => $tenant->created_at,
                'owner_name' => $owner ? $owner->name : 'N/A',
                'owner_email' => $owner ? $owner->email : 'N/A',
                'users_count' => $userCount
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
                'total' => $tenants->total(),
                'items' => $items
            ]
        ]);
    }

    /**
     * Update tenant workspace name or SaaS plan.
     */
    public function updateTenant(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'plan' => 'required|string|exists:plans,key'
        ]);

        $tenant->update([
            'name' => $request->name,
            'plan' => $request->plan
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tenant updated successfully',
            'tenant' => $tenant
        ]);
    }

    /**
     * Delete a tenant.
     */
    public function deleteTenant($id)
    {
        $tenant = Tenant::findOrFail($id);
        
        // Remove tenant relation from users belonging to it
        User::where('tenant_id', $tenant->id)->update(['tenant_id' => null]);
        
        $tenant->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tenant deleted successfully'
        ]);
    }

    /**
     * List all users across all tenants.
     */
    public function usersIndex(Request $request)
    {
        $query = User::with('tenant');

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where('name', 'like', $search)
                  ->orWhere('email', 'like', $search);
        }

        $users = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }

    /**
     * Update user details or role.
     */
    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'required|string|in:superadmin,admin,agent,user',
            'status' => 'required|string|in:active,blocked',
            'password' => 'nullable|min:6'
        ]);

        if (auth()->id() === $user->id && $request->status === 'blocked') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot suspend your own superadmin account'
            ], 400);
        }

        $user->name = $request->name;
        $user->role = $request->role;
        $user->status = $request->status;

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'user' => $user
        ]);
    }

    /**
     * Delete a user.
     */
    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        
        if (auth()->id() === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete your own superadmin account'
            ], 400);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }

    /**
     * Get pricing plans and system limits.
     */
    public function plansIndex()
    {
        $plans = \App\Models\Plan::all();

        return response()->json([
            'success' => true,
            'plans' => $plans
        ]);
    }

    /**
     * Create a new pricing plan limit deck.
     */
    public function createPlan(Request $request)
    {
        $validated = $request->validate([
            'key' => 'required|string|unique:plans,key',
            'name' => 'required|string',
            'price' => 'required|string',
            'limits' => 'required|array',
            'limits.contacts' => 'required|string',
            'limits.messages' => 'required|string',
            'limits.campaigns' => 'required|string',
            'limits.ai_replies' => 'required|string',
        ]);

        $plan = \App\Models\Plan::create([
            'key' => $validated['key'],
            'name' => $validated['name'],
            'price' => $validated['price'],
            'limits' => $validated['limits'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Plan created successfully',
            'plan' => $plan
        ]);
    }

    /**
     * Update an existing plan's parameters.
     */
    public function updatePlan(Request $request, $id)
    {
        $plan = \App\Models\Plan::findOrFail($id);

        $validated = $request->validate([
            'key' => 'required|string|unique:plans,key,' . $plan->id,
            'name' => 'required|string',
            'price' => 'required|string',
            'limits' => 'required|array',
            'limits.contacts' => 'required|string',
            'limits.messages' => 'required|string',
            'limits.campaigns' => 'required|string',
            'limits.ai_replies' => 'required|string',
        ]);

        $plan->update([
            'key' => $validated['key'],
            'name' => $validated['name'],
            'price' => $validated['price'],
            'limits' => $validated['limits'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Plan updated successfully',
            'plan' => $plan
        ]);
    }

    /**
     * Terminate / delete a subscription plan key.
     */
    public function deletePlan($id)
    {
        $plan = \App\Models\Plan::findOrFail($id);

        $inUse = \App\Models\Tenant::where('plan', $plan->key)->exists();
        if ($inUse) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a plan that is currently assigned to a tenant workspace.'
            ], 400);
        }

        $plan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Plan deleted successfully'
        ]);
    }

    /**
     * Get global settings configurations.
     */
    public function settingsIndex()
    {
        $settings = [
            'meta_api_version' => 'v19.0',
            'global_backup_frequency' => 'daily',
            'reverb_host' => '127.0.0.1',
            'reverb_port' => '8080',
            'support_email' => 'support@throbtech.com'
        ];

        return response()->json([
            'success' => true,
            'settings' => $settings
        ]);
    }

    /**
     * Update global system configurations.
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'meta_api_version' => 'required|string',
            'global_backup_frequency' => 'required|string|in:hourly,daily,weekly',
            'reverb_host' => 'required|string',
            'reverb_port' => 'required|string',
            'support_email' => 'required|email'
        ]);

        // In a real application, you would save these configs in database or .env file
        // Here we simulate successful save response

        return response()->json([
            'success' => true,
            'message' => 'Global settings updated successfully (Simulated)'
        ]);
    }
}
