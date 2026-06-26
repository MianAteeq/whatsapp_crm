<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function insights()
    {
        $tenantId = auth()->user()->tenant_id;



        // =====================================================
        // TOTALS
        // =====================================================

        $totalSent = WhatsappMessageLog::where(
            'tenant_id',
            $tenantId
        )->count();



        $totalDelivered = WhatsappMessageLog::where(
            'tenant_id',
            $tenantId
        )
            ->whereNotNull('delivered_at')
            ->count();



        $totalRead = WhatsappMessageLog::where(
            'tenant_id',
            $tenantId
        )
            ->whereNotNull('read_at')
            ->count();



        $totalReplies = WhatsappMessageLog::where(
            'tenant_id',
            $tenantId
        )
            ->whereNotNull('replied_at')
            ->count();



        // =====================================================
        // HERO KPIs
        // =====================================================

        $networkReach = Campaign::where(
            'tenant_id',
            $tenantId
        )
            ->distinct('template_id')
            ->count();



        $transmissionVolume = $totalSent;



        $strategicOps = Campaign::where(
            'tenant_id',
            $tenantId
        )
            ->whereIn('status', [

                'running',

                'queued',

                'scheduled'

            ])
            ->count();



        $engagementRate = $totalDelivered > 0

            ? round(
                ($totalRead / $totalDelivered) * 100,
                1
            )

            : 0;



        // =====================================================
        // TRANSMISSION CHART
        // =====================================================

        $transmissionChart = WhatsappMessageLog::select(

            DB::raw("DATE(created_at) as date"),

            DB::raw("COUNT(*) as sent"),

            DB::raw("
    SUM(
        CASE
            WHEN read_at IS NOT NULL
            THEN 1
            ELSE 0
        END
    ) as total_reads
")

        )
            ->where('tenant_id', $tenantId)
            ->whereDate(
                'created_at',
                '>=',
                now()->subDays(30)
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {

                return [

                    'date' => $item->date,

                    'sent' => (int) $item->sent,

                    'reads' => (int) $item->reads,

                ];
            });



        // =====================================================
        // CATEGORY HEALTH
        // =====================================================

        $categoryHealth = WhatsappTemplate::select(

            'category',

            DB::raw('COUNT(*) as total')

        )
            ->where('tenant_id', $tenantId)
            ->groupBy('category')
            ->get();



        $categoryTotal = $categoryHealth->sum('total');



        $categoryHealth = $categoryHealth->map(function ($item) use ($categoryTotal) {

            return [

                'category' => strtoupper(
                    $item->category
                ),

                'count' => (int) $item->total,

                'percentage' => $categoryTotal > 0

                    ? round(
                        ($item->total / $categoryTotal) * 100
                    )

                    : 0

            ];
        });



        // =====================================================
        // ACTIVE CAMPAIGNS
        // =====================================================

        $campaigns = Campaign::latest()

            ->where(
                'tenant_id',
                $tenantId
            )

            ->take(10)

            ->get()

            ->map(function ($campaign) {

                $efficiency = $campaign->sent_count > 0

                    ? round(
                        (
                            $campaign->read_count
                            /
                            $campaign->sent_count
                        ) * 100
                    )

                    : 0;



                return [

                    'id' => $campaign->id,

                    'name' => $campaign->name,

                    'type' => $campaign->type,

                    'status' => $campaign->status,

                    'total_recipients' => $campaign->total_contacts,

                    'sent_count' => $campaign->sent_count,

                    'delivered_count' => $campaign->delivered_count,

                    'read_count' => $campaign->read_count,

                    'efficiency' => $efficiency,

                    'scheduled_at' => $campaign->scheduled_at,

                    'created_at' => $campaign->created_at,

                ];
            });



        // =====================================================
        // TOP TEMPLATES
        // =====================================================

        $templates = WhatsappMessageLog::select(

            'template_name',

            DB::raw('COUNT(*) as total_sent'),

            DB::raw("
            SUM(
                CASE
                    WHEN delivered_at IS NOT NULL
                    THEN 1
                    ELSE 0
                END
            ) as total_delivered
        "),

            DB::raw("
            SUM(
                CASE
                    WHEN read_at IS NOT NULL
                    THEN 1
                    ELSE 0
                END
            ) as total_read
        "),

            DB::raw("
            SUM(
                CASE
                    WHEN replied_at IS NOT NULL
                    THEN 1
                    ELSE 0
                END
            ) as total_replied
        ")

        )
            ->where('tenant_id', $tenantId)
            ->groupBy('template_name')
            ->orderByDesc('total_sent')
            ->get();



        $topTemplates = $templates->map(function ($item) {

            $sent = (int) $item->total_sent;

            $delivered = (int) $item->total_delivered;

            $read = (int) $item->total_read;

            $replied = (int) $item->total_replied;



            if ($read > $delivered) {

                $delivered = $read;
            }



            $deliveryRate = $sent > 0

                ? round(
                    ($delivered / $sent) * 100,
                    1
                )

                : 0;



            $readRate = $delivered > 0

                ? round(
                    ($read / $delivered) * 100,
                    1
                )

                : 0;



            return [

                'template_name' => $item->template_name,

                'sent' => $sent,

                'delivered' => $delivered,

                'reads' => $read,

                'replies' => $replied,

                'delivery_rate' => $deliveryRate,

                'read_rate' => $readRate,

                'status' => $readRate >= 70

                    ? 'excellent'

                    : (

                        $readRate >= 40

                        ? 'good'

                        : 'poor'

                    ),

            ];
        });



        // =====================================================
        // RECENT ACTIVITY
        // =====================================================

        $recentActivity = WhatsappMessageLog::latest()

            ->where(
                'tenant_id',
                $tenantId
            )

            ->take(10)

            ->get([

                'template_name',

                'recipient',

                'status',

                'created_at'

            ]);



        // =====================================================
        // AI ALERTS
        // =====================================================

        $alerts = [

            [

                'type' => 'upgrade',

                'title' => 'Strategic Automation Upgrade Available',

                'description' => 'Deploy neural-optimized delivery intelligence to improve campaign engagement performance.',

                'action' => 'Activate Intelligence'

            ]

        ];



        $totalConversations = \App\Models\Conversation::where('tenant_id', $tenantId)->count();
        $totalContacts = \App\Models\Contact::where('tenant_id', $tenantId)->count();
        $campaignsCount = Campaign::where('tenant_id', $tenantId)->count();

        $leadsCount = \App\Models\Contact::where('tenant_id', $tenantId)
            ->whereIn('status', ['lead', 'customer', 'active'])
            ->count();
            
        $convertedCount = \App\Models\Contact::where('tenant_id', $tenantId)
            ->where('status', 'customer')
            ->count();

        // =====================================================
        // FINAL RESPONSE
        // =====================================================

        return response()->json([

            'success' => true,



            // =================================================
            // HERO CARDS
            // =================================================

            'hero_cards' => [

                'network_reach' => $networkReach,

                'transmission_volume' => $transmissionVolume,

                'strategic_ops' => $strategicOps,

                'engagement_rate' => $engagementRate,

                'total_conversations' => $totalConversations,

                'total_contacts' => $totalContacts,

                'campaigns_count' => $campaignsCount,

                'leads_count' => $leadsCount,

                'converted_count' => $convertedCount,

            ],



            // =================================================
            // CHARTS
            // =================================================

            'charts' => [

                'transmission_performance' => $transmissionChart,

            ],



            // =================================================
            // CATEGORY HEALTH
            // =================================================

            'category_health' => [

                'total' => $categoryTotal,

                'items' => $categoryHealth,

            ],



            // =================================================
            // CAMPAIGNS
            // =================================================

            'campaigns' => $campaigns,



            // =================================================
            // TOP TEMPLATES
            // =================================================

            'top_templates' => $topTemplates,



            // =================================================
            // RECENT ACTIVITY
            // =================================================

            'recent_activity' => $recentActivity,



            // =================================================
            // ALERTS
            // =================================================

            'alerts' => $alerts,



            // =================================================
            // GLOBAL STATS
            // =================================================

            'stats' => [

                'total_sent' => $totalSent,

                'total_delivered' => $totalDelivered,

                'total_read' => $totalRead,

                'total_replies' => $totalReplies,

                'delivery_rate' => $totalSent > 0

                    ? round(
                        ($totalDelivered / $totalSent) * 100,
                        1
                    )

                    : 0,

                'read_rate' => $totalDelivered > 0

                    ? round(
                        ($totalRead / $totalDelivered) * 100,
                        1
                    )

                    : 0,

            ]

        ]);
    }

    public function notifications()
    {
        $tenantId = auth()->user()->tenant_id;
        $notifications = [];
        $id = 1;

        // 1. Unread conversations
        try {
            $unreadConversations = \App\Models\Conversation::with('contact')
                ->where('tenant_id', $tenantId)
                ->where('unread_count', '>', 0)
                ->latest('updated_at')
                ->take(5)
                ->get();

            foreach ($unreadConversations as $conv) {
                if ($conv->contact) {
                    $notifications[] = [
                        'id' => $id++,
                        'title' => 'New Message Inbound',
                        'desc' => "Customer {$conv->contact->name} ({$conv->contact->phone}) sent a new message.",
                        'time' => $conv->updated_at ? $conv->updated_at->diffForHumans() : 'Just now',
                        'unread' => true,
                        'category' => 'inbox',
                        'action_path' => "/inbox?select={$conv->id}"
                    ];
                }
            }
        } catch (\Exception $e) {
            // Ignore error
        }

        // 2. Campaigns
        try {
            $campaigns = \App\Models\Campaign::where('tenant_id', $tenantId)
                ->latest()
                ->take(3)
                ->get();

            foreach ($campaigns as $camp) {
                $statusStr = ucfirst(strtolower($camp->status));
                $isComplete = strtolower($camp->status) === 'completed';
                $notifications[] = [
                    'id' => $id++,
                    'title' => "Campaign {$statusStr}",
                    'desc' => "Campaign \"{$camp->name}\" status is now {$camp->status}.",
                    'time' => $camp->updated_at ? $camp->updated_at->diffForHumans() : 'Recently',
                    'unread' => !$isComplete,
                    'category' => 'campaign',
                    'action_path' => "/campaigns/{$camp->id}"
                ];
            }
        } catch (\Exception $e) {
            // Ignore error
        }

        // 3. Templates
        try {
            $templates = \App\Models\WhatsappTemplate::where('tenant_id', $tenantId)
                ->latest()
                ->take(2)
                ->get();

            foreach ($templates as $temp) {
                $notifications[] = [
                    'id' => $id++,
                    'title' => 'Template Synced',
                    'desc' => "WhatsApp template \"{$temp->template_name}\" was successfully synced.",
                    'time' => $temp->created_at ? $temp->created_at->diffForHumans() : 'Recently',
                    'unread' => false,
                    'category' => 'whatsapp',
                    'action_path' => "/templates"
                ];
            }
        } catch (\Exception $e) {
            // Ignore error
        }

        // 4. Default System notifications if none
        if (count($notifications) === 0) {
            $notifications[] = [
                'id' => $id++,
                'title' => 'Database Synced',
                'desc' => 'Contacts index synchronized with clean schema references.',
                'time' => 'Recently',
                'unread' => false,
                'category' => 'system',
                'action_path' => '/dashboard'
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $notifications
        ]);
    }
}
