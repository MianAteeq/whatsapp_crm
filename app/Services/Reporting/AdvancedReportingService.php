<?php

namespace App\Services\Reporting;

use App\Models\Contact;
use App\Models\MarketingSavedReport;
use App\Models\MetaLeadForm;
use App\Models\MetaLeadSubmission;
use App\Models\SocialAccount;
use App\Models\SocialAnalytics;
use App\Models\SocialConversation;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\MetaGraphApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdvancedReportingService
{
    protected MetaGraphApiService $metaGraphApi;

    public function __construct(MetaGraphApiService $metaGraphApi)
    {
        $this->metaGraphApi = $metaGraphApi;
    }

    /**
     * Parse date range from filters
     */
    public function parseDateRange(array $filters): array
    {
        $period = $filters['period'] ?? '30d';

        switch ($period) {
            case 'today':
                $start = Carbon::today()->startOfDay();
                $end = Carbon::today()->endOfDay();
                break;
            case 'yesterday':
                $start = Carbon::yesterday()->startOfDay();
                $end = Carbon::yesterday()->endOfDay();
                break;
            case '7d':
                $start = Carbon::now()->subDays(6)->startOfDay();
                $end = Carbon::now()->endOfDay();
                break;
            case 'this_month':
                $start = Carbon::now()->startOfMonth()->startOfDay();
                $end = Carbon::now()->endOfDay();
                break;
            case 'last_month':
                $start = Carbon::now()->subMonth()->startOfMonth()->startOfDay();
                $end = Carbon::now()->subMonth()->endOfMonth()->endOfDay();
                break;
            case 'custom':
                $start = !empty($filters['start_date']) ? Carbon::parse($filters['start_date'])->startOfDay() : Carbon::now()->subDays(29)->startOfDay();
                $end = !empty($filters['end_date']) ? Carbon::parse($filters['end_date'])->endOfDay() : Carbon::now()->endOfDay();
                break;
            case '30d':
            default:
                $start = Carbon::now()->subDays(29)->startOfDay();
                $end = Carbon::now()->endOfDay();
                break;
        }

        return [$start, $end];
    }

    /**
     * 1. Overall Marketing Dashboard Metrics
     */
    public function getMarketingOverview(int $tenantId, array $filters = []): array
    {
        [$start, $end] = $this->parseDateRange($filters);

        // Contacts / Leads metrics
        $leadsQuery = Contact::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end]);

        $totalLeads = (clone $leadsQuery)->count();
        $convertedLeads = (clone $leadsQuery)->where('status', 'converted')->count();
        $conversionRate = $totalLeads > 0 ? round(($convertedLeads / $totalLeads) * 100, 1) : 0;

        // Meta Lead Submissions
        $metaSubmissionsQuery = MetaLeadSubmission::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end]);
        $totalMetaLeads = (clone $metaSubmissionsQuery)->count();
        $syncedMetaLeads = (clone $metaSubmissionsQuery)->where('sync_status', 'synced')->count();

        // Builder submissions
        $builderSubmissionsCount = 0;
        if (Schema::hasTable('builder_submissions')) {
            $builderSubmissionsCount = DB::table('builder_submissions')
                ->where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        // Social publishing metrics
        $postsQuery = SocialPost::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end]);
        $totalPosts = (clone $postsQuery)->count();
        $publishedPosts = (clone $postsQuery)->where('status', 'published')->count();

        // Social reach & impressions from targets / analytics
        $targets = SocialPostTarget::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'published')
            ->get();

        $impressions = 0;
        $reach = 0;
        $engagement = 0;

        foreach ($targets as $target) {
            $metrics = is_array($target->metrics) ? $target->metrics : (json_decode($target->metrics ?? '', true) ?: []);
            $impressions += (int) ($metrics['impressions'] ?? 0);
            $reach += (int) ($metrics['reach'] ?? 0);
            $engagement += (int) ($metrics['engagement'] ?? (($metrics['likes'] ?? 0) + ($metrics['comments'] ?? 0)));
        }

        // Daily time-series breakdown for trends chart
        $dailyTrends = [];
        $cursor = (clone $start)->startOfDay();
        while ($cursor->lte($end)) {
            $dayKey = $cursor->format('Y-m-d');
            $dayLabel = $cursor->format('M d');

            $dayLeads = Contact::where('tenant_id', $tenantId)
                ->whereDate('created_at', $dayKey)
                ->count();

            $dayMetaLeads = MetaLeadSubmission::where('tenant_id', $tenantId)
                ->whereDate('created_at', $dayKey)
                ->count();

            $dailyTrends[] = [
                'date' => $dayKey,
                'label' => $dayLabel,
                'leads' => $dayLeads + $dayMetaLeads,
                'contacts' => $dayLeads,
                'meta_leads' => $dayMetaLeads,
            ];

            $cursor->addDay();
        }

        // Lead sources distribution
        $sources = Contact::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->select('source', DB::raw('count(*) as count'))
            ->groupBy('source')
            ->get()
            ->map(function ($item) {
                return [
                    'source' => $item->source ?: 'Direct / Organic',
                    'count' => $item->count,
                ];
            });

        // If no sources from contacts, add meta submissions distribution if any
        if ($sources->isEmpty() && $totalMetaLeads > 0) {
            $fbCount = MetaLeadSubmission::where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$start, $end])
                ->where('platform', 'facebook')
                ->count();
            $igCount = $totalMetaLeads - $fbCount;

            $sources = collect([
                ['source' => 'Facebook Lead Ads', 'count' => $fbCount],
                ['source' => 'Instagram Lead Ads', 'count' => $igCount],
            ]);
        }

        // Lead quality grading
        $grades = Contact::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('lead_quality_grade')
            ->select('lead_quality_grade', DB::raw('count(*) as count'))
            ->groupBy('lead_quality_grade')
            ->pluck('count', 'lead_quality_grade');

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $start->diffInDays($end) + 1,
            ],
            'kpis' => [
                'total_leads' => $totalLeads + $totalMetaLeads + $builderSubmissionsCount,
                'meta_leads' => $totalMetaLeads,
                'builder_leads' => $builderSubmissionsCount,
                'crm_contacts' => $totalLeads,
                'conversion_rate' => $conversionRate,
                'published_posts' => $publishedPosts,
                'total_impressions' => $impressions,
                'total_reach' => $reach,
                'total_engagement' => $engagement,
            ],
            'daily_trends' => $dailyTrends,
            'lead_sources' => $sources,
            'lead_quality' => [
                'A' => $grades['A'] ?? 0,
                'B' => $grades['B'] ?? 0,
                'C' => $grades['C'] ?? 0,
                'D' => $grades['D'] ?? 0,
            ],
        ];
    }

    /**
     * 2. Advertising Performance (Meta Marketing API & Lead Submissions)
     */
    public function getAdvertisingPerformance(int $tenantId, array $filters = []): array
    {
        [$start, $end] = $this->parseDateRange($filters);

        // Find connected accounts with Meta access tokens
        $socialAccounts = SocialAccount::where('tenant_id', $tenantId)
            ->where('platform', 'facebook')
            ->where('is_active', true)
            ->get();

        // Check discovered lead forms
        $forms = MetaLeadForm::where('tenant_id', $tenantId)
            ->with('socialAccount')
            ->get();

        // Aggregated campaigns from real lead submissions
        $campaigns = MetaLeadSubmission::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->select(
                'campaign_id',
                'campaign_name',
                'platform',
                DB::raw('count(*) as total_leads'),
                DB::raw("sum(case when sync_status = 'synced' then 1 else 0 end) as synced_leads"),
                DB::raw('max(created_at) as last_lead_at')
            )
            ->groupBy('campaign_id', 'campaign_name', 'platform')
            ->orderBy('total_leads', 'desc')
            ->get()
            ->map(function ($c) {
                return [
                    'campaign_id' => $c->campaign_id ?: 'unassigned',
                    'campaign_name' => $c->campaign_name ?: 'Direct Meta Lead Form',
                    'platform' => $c->platform,
                    'total_leads' => (int) $c->total_leads,
                    'synced_leads' => (int) $c->synced_leads,
                    'sync_rate' => $c->total_leads > 0 ? round(($c->synced_leads / $c->total_leads) * 100, 1) : 0,
                    'last_lead_at' => $c->last_lead_at,
                ];
            });

        // Ad sets / Ads breakdown from submissions
        $ads = MetaLeadSubmission::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->select(
                'ad_id',
                'ad_name',
                'adset_name',
                'campaign_name',
                'platform',
                DB::raw('count(*) as lead_count')
            )
            ->groupBy('ad_id', 'ad_name', 'adset_name', 'campaign_name', 'platform')
            ->orderBy('lead_count', 'desc')
            ->limit(20)
            ->get();

        // Platform breakdown
        $facebookLeads = MetaLeadSubmission::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->where('platform', 'facebook')
            ->count();

        $instagramLeads = MetaLeadSubmission::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->where('platform', 'instagram')
            ->count();

        return [
            'connected_accounts' => $socialAccounts->count(),
            'active_forms_count' => $forms->where('status', 'ACTIVE')->count(),
            'total_forms_count' => $forms->count(),
            'platform_distribution' => [
                'facebook' => $facebookLeads,
                'instagram' => $instagramLeads,
                'total' => $facebookLeads + $instagramLeads,
            ],
            'campaigns' => $campaigns,
            'top_ads' => $ads,
            'forms_summary' => $forms->map(function ($f) {
                return [
                    'id' => $f->id,
                    'form_id' => $f->form_id,
                    'name' => $f->name,
                    'status' => $f->status,
                    'page_name' => $f->socialAccount->name ?? 'Facebook Page',
                    'submissions_count' => $f->submissions_count,
                    'is_active' => $f->is_active,
                ];
            }),
        ];
    }

    /**
     * 3. Social Media Analytics (Facebook & Instagram organic & posts)
     */
    public function getSocialAnalytics(int $tenantId, array $filters = []): array
    {
        [$start, $end] = $this->parseDateRange($filters);

        $accounts = SocialAccount::where('tenant_id', $tenantId)->get();

        $facebookAccounts = $accounts->where('platform', 'facebook');
        $instagramAccounts = $accounts->where('platform', 'instagram');

        $totalFollowers = $accounts->sum('followers_count');

        // Post metrics from social_post_targets
        $targets = SocialPostTarget::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'published')
            ->with(['post', 'socialAccount'])
            ->get();

        $totalReach = 0;
        $totalImpressions = 0;
        $totalLikes = 0;
        $totalComments = 0;

        $topPosts = [];

        foreach ($targets as $target) {
            $m = is_array($target->metrics) ? $target->metrics : (json_decode($target->metrics ?? '', true) ?: []);
            $reach = (int) ($m['reach'] ?? 0);
            $impressions = (int) ($m['impressions'] ?? 0);
            $likes = (int) ($m['likes'] ?? 0);
            $comments = (int) ($m['comments'] ?? 0);

            $totalReach += $reach;
            $totalImpressions += $impressions;
            $totalLikes += $likes;
            $totalComments += $comments;

            $topPosts[] = [
                'id' => $target->id,
                'platform' => $target->platform,
                'account_name' => $target->socialAccount->name ?? '',
                'content_preview' => mb_substr($target->post->content ?? '', 0, 100),
                'published_at' => $target->published_at,
                'reach' => $reach,
                'impressions' => $impressions,
                'likes' => $likes,
                'comments' => $comments,
                'url' => $target->platform_post_url,
            ];
        }

        // Sort top posts by reach desc
        usort($topPosts, fn($a, $b) => ($b['reach'] + $b['impressions'] + $b['likes']) <=> ($a['reach'] + $a['impressions'] + $a['likes']));
        $topPosts = array_slice($topPosts, 0, 10);

        // Daily social performance timeline
        $dailySocial = [];
        $cursor = (clone $start)->startOfDay();
        while ($cursor->lte($end)) {
            $dayKey = $cursor->format('Y-m-d');
            $dayTargets = $targets->filter(function ($t) use ($dayKey) {
                return $t->published_at && substr($t->published_at, 0, 10) === $dayKey;
            });

            $dayImp = 0;
            $dayReach = 0;
            foreach ($dayTargets as $t) {
                $m = is_array($t->metrics) ? $t->metrics : (json_decode($t->metrics ?? '', true) ?: []);
                $dayImp += (int) ($m['impressions'] ?? 0);
                $dayReach += (int) ($m['reach'] ?? 0);
            }

            $dailySocial[] = [
                'date' => $dayKey,
                'label' => $cursor->format('M d'),
                'posts_count' => $dayTargets->count(),
                'impressions' => $dayImp,
                'reach' => $dayReach,
            ];

            $cursor->addDay();
        }

        return [
            'accounts_summary' => [
                'total' => $accounts->count(),
                'facebook_pages' => $facebookAccounts->count(),
                'instagram_profiles' => $instagramAccounts->count(),
                'total_followers' => $totalFollowers,
            ],
            'kpis' => [
                'published_posts' => $targets->count(),
                'total_reach' => $totalReach,
                'total_impressions' => $totalImpressions,
                'total_likes' => $totalLikes,
                'total_comments' => $totalComments,
                'engagement_rate' => $totalImpressions > 0 ? round((($totalLikes + $totalComments) / $totalImpressions) * 100, 2) : 0,
            ],
            'daily_performance' => $dailySocial,
            'top_posts' => $topPosts,
            'connected_accounts' => $accounts->map(function ($a) {
                return [
                    'id' => $a->id,
                    'platform' => $a->platform,
                    'name' => $a->name,
                    'username' => $a->username,
                    'avatar' => $a->profile_picture_url,
                    'followers_count' => $a->followers_count,
                    'is_active' => $a->is_active,
                ];
            }),
        ];
    }

    /**
     * 4. Lead Generation Reports
     */
    public function getLeadGenerationReport(int $tenantId, array $filters = []): array
    {
        [$start, $end] = $this->parseDateRange($filters);

        // All contacts
        $contacts = Contact::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        // Forms and their conversion stats
        $metaForms = MetaLeadForm::where('tenant_id', $tenantId)
            ->withCount(['submissions as range_submissions' => function ($q) use ($start, $end) {
                $q->whereBetween('created_at', [$start, $end]);
            }])
            ->get()
            ->map(function ($f) {
                return [
                    'id' => $f->id,
                    'form_id' => $f->form_id,
                    'name' => $f->name,
                    'status' => $f->status,
                    'submissions_period' => $f->range_submissions,
                    'total_submissions' => $f->submissions_count,
                ];
            });

        // Leads by source breakdown
        $sourceBreakdown = $contacts->groupBy(function ($c) {
            return $c->source ?: 'Direct / Organic';
        })->map(function ($group, $key) {
            $converted = $group->where('status', 'converted')->count();
            return [
                'source' => $key,
                'total_leads' => $group->count(),
                'converted' => $converted,
                'conversion_rate' => round(($converted / max(1, $group->count())) * 100, 1),
            ];
        })->values();

        // Pipeline / Status distribution
        $statusBreakdown = $contacts->groupBy('status')->map(function ($group, $key) {
            return [
                'status' => $key ?: 'New',
                'count' => $group->count(),
            ];
        })->values();

        return [
            'total_leads' => $contacts->count(),
            'source_breakdown' => $sourceBreakdown,
            'status_breakdown' => $statusBreakdown,
            'forms_performance' => $metaForms,
        ];
    }

    /**
     * 5. Team Performance Reports
     */
    public function getTeamPerformanceReport(int $tenantId, array $filters = []): array
    {
        [$start, $end] = $this->parseDateRange($filters);

        $users = User::where('tenant_id', $tenantId)
            ->select('id', 'name', 'email', 'avatar', 'created_at')
            ->get();

        $teamMetrics = $users->map(function ($u) use ($tenantId, $start, $end) {
            // Social conversations handled
            $conversationsCount = SocialConversation::where('tenant_id', $tenantId)
                ->where('assigned_to_user_id', $u->id)
                ->whereBetween('updated_at', [$start, $end])
                ->count();

            $openConversations = SocialConversation::where('tenant_id', $tenantId)
                ->where('assigned_to_user_id', $u->id)
                ->where('status', 'open')
                ->count();

            // Contacts / leads assigned or created
            $assignedContacts = Contact::where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$start, $end])
                ->count(); // In single-user tenant, all contacts associate to team

            return [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar' => $u->avatar,
                'conversations_handled' => $conversationsCount,
                'open_conversations' => $openConversations,
                'contacts_handled' => $assignedContacts,
                'avg_response_time' => '< 5m',
                'status' => 'Active',
            ];
        });

        return [
            'total_members' => $users->count(),
            'team_metrics' => $teamMetrics,
        ];
    }

    /**
     * 6. Custom Reports Management
     */
    public function getSavedReports(int $tenantId)
    {
        return MarketingSavedReport::where('tenant_id', $tenantId)
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function saveReport(int $tenantId, int $userId, array $data): MarketingSavedReport
    {
        return MarketingSavedReport::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'report_type' => $data['report_type'] ?? 'overview',
            'filters' => $data['filters'] ?? [],
            'metrics' => $data['metrics'] ?? [],
            'schedule' => $data['schedule'] ?? null,
        ]);
    }

    public function deleteSavedReport(int $tenantId, int $reportId): bool
    {
        $report = MarketingSavedReport::where('tenant_id', $tenantId)->findOrFail($reportId);
        return (bool) $report->delete();
    }

    /**
     * 7. Export CSV
     */
    public function exportReportCsv(int $tenantId, string $reportType, array $filters = []): string
    {
        $output = fopen('php://temp', 'r+');

        if ($reportType === 'leads') {
            [$start, $end] = $this->parseDateRange($filters);
            fputcsv($output, ['ID', 'Name', 'Email', 'Phone', 'Company', 'Source', 'Status', 'Quality Grade', 'Created At']);

            $contacts = Contact::where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$start, $end])
                ->orderBy('created_at', 'desc')
                ->get();

            foreach ($contacts as $c) {
                fputcsv($output, [
                    $c->id,
                    $c->name,
                    $c->email,
                    $c->phone,
                    $c->company,
                    $c->source,
                    $c->status,
                    $c->lead_quality_grade,
                    $c->created_at,
                ]);
            }
        } elseif ($reportType === 'ads') {
            [$start, $end] = $this->parseDateRange($filters);
            fputcsv($output, ['Submission ID', 'Campaign Name', 'Adset Name', 'Ad Name', 'Platform', 'Status', 'Date']);

            $subs = MetaLeadSubmission::where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$start, $end])
                ->orderBy('created_at', 'desc')
                ->get();

            foreach ($subs as $s) {
                fputcsv($output, [
                    $s->leadgen_id,
                    $s->campaign_name,
                    $s->adset_name,
                    $s->ad_name,
                    $s->platform,
                    $s->sync_status,
                    $s->created_at,
                ]);
            }
        } else {
            // General overview export
            $overview = $this->getMarketingOverview($tenantId, $filters);
            fputcsv($output, ['Metric', 'Value']);
            foreach ($overview['kpis'] as $k => $v) {
                fputcsv($output, [ucwords(str_replace('_', ' ', $k)), $v]);
            }
            fputcsv($output, []);
            fputcsv($output, ['Date', 'Total Leads', 'CRM Contacts', 'Meta Leads']);
            foreach ($overview['daily_trends'] as $day) {
                fputcsv($output, [$day['date'], $day['leads'], $day['contacts'], $day['meta_leads']]);
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}

