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
            ) as reads
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
}
