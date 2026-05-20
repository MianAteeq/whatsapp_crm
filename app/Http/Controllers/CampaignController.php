<?php

namespace App\Http\Controllers;

use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CampaignController extends Controller
{
    public function store(Request $request)
    {

        $request->validate([

            'name' => 'required|string|max:255',

            'type' => 'required|string',

            'template_id' => 'required|exists:whatsapp_templates,id',

            'audience_type' => 'required|string',

            'scheduled_at' => 'nullable|date',

            'tag_ids' => 'nullable|array',

            'contact_ids' => 'nullable|array'

        ]);



        DB::beginTransaction();



        try {

            // ==========================================
            // CREATE CAMPAIGN
            // ==========================================

            $campaign = Campaign::create([

                'tenant_id' => auth()->user()->tenant_id,

                'name' => $request->name,

                'type' => $request->type,

                'template_id' => $request->template_id,

                'status' => $request->scheduled_at
                    ? 'scheduled'
                    : 'draft',

                'scheduled_at' => $request->scheduled_at

                    ? Carbon::parse(
                        $request->scheduled_at
                    )->format('Y-m-d H:i:s')

                    : null

            ]);



            // ==========================================
            // CONTACTS QUERY
            // ==========================================

            $contactsQuery = Contact::where(

                'tenant_id',
                auth()->user()->tenant_id

            );



            // ==========================================
            // ALL CONTACTS
            // ==========================================

            if ($request->audience_type === 'all_contacts') {

                $contacts = $contactsQuery->get();
            }



            // ==========================================
            // TAG SEGMENT
            // ==========================================

            elseif (

                $request->audience_type === 'segment'

                &&

                $request->tag_ids

            ) {

                $contacts = $contactsQuery

                    ->whereHas(

                        'tags',

                        function ($query) use ($request) {

                            $query->whereIn(

                                'tags.id',

                                $request->tag_ids

                            );
                        }

                    )

                    ->get();
            }



            // ==========================================
            // MANUAL CONTACTS
            // ==========================================

            elseif (

                $request->audience_type === 'manual'

                &&

                $request->contact_ids

            ) {

                $contacts = $contactsQuery

                    ->whereIn(

                        'id',

                        $request->contact_ids

                    )

                    ->get();
            }



            // ==========================================
            // INVALID AUDIENCE
            // ==========================================

            else {

                return response()->json([

                    'success' => false,

                    'message' => 'Invalid audience selection'

                ], 422);
            }



            // ==========================================
            // STORE TOTAL CONTACTS
            // ==========================================

            $campaign->update([

                'total_contacts' => $contacts->count()

            ]);



            // ==========================================
            // STORE CAMPAIGN CONTACTS
            // ==========================================

            foreach ($contacts as $contact) {

                CampaignContact::create([

                    'campaign_id' => $campaign->id,

                    'contact_id' => $contact->id,

                    'status' => 'pending'

                ]);
            }



            // ==========================================
            // DISPATCH CAMPAIGN JOB
            // ==========================================

            if ($request->scheduled_at) {

                SendCampaignJob::dispatch(

                    $campaign->id

                )->delay(

                    Carbon::parse(
                        $request->scheduled_at
                    )

                );
            } else {

                SendCampaignJob::dispatch(

                    $campaign->id

                );
            }



            // ==========================================
            // COMMIT
            // ==========================================

            DB::commit();



            // ==========================================
            // RESPONSE
            // ==========================================

            return response()->json([

                'success' => true,

                'message' => 'Campaign created successfully',

                'data' => $campaign

            ]);
        } catch (\Exception $e) {

            DB::rollBack();



            return response()->json([

                'success' => false,

                'message' => $e->getMessage()

            ], 500);
        }
    }

    public function dashboard()
    {

        $tenantId = auth()->user()->tenant_id;



        // ==========================================
        // ACTIVE CAMPAIGNS
        // ==========================================

        $activeCampaigns = Campaign::where(

            'tenant_id',

            $tenantId

        )

            ->whereIn('status', [

                'running',

                'queued',

                'scheduled'

            ])

            ->count();



        // ==========================================
        // TOTAL MESSAGES
        // ==========================================

        $totalMessages = Campaign::where(

            'tenant_id',

            $tenantId

        )->sum('sent_count');



        // ==========================================
        // TOTAL DELIVERED
        // ==========================================

        $totalDelivered = Campaign::where(

            'tenant_id',

            $tenantId

        )->sum('delivered_count');



        // ==========================================
        // REACH RATE
        // ==========================================

        $reachRate = 0;



        if ($totalMessages > 0) {

            $reachRate = round(

                ($totalDelivered / $totalMessages) * 100,

                2

            );
        }



        return response()->json([

            'success' => true,

            'data' => [

                'active_campaigns' => $activeCampaigns,

                'total_messages' => $totalMessages,

                'reach_rate' => $reachRate

            ]

        ]);
    }

    public function index(Request $request)
    {

        $campaigns = Campaign::where(

            'tenant_id',

            auth()->user()->tenant_id

        );



        // ==========================================
        // SEARCH
        // ==========================================

        if ($request->search) {

            $campaigns->where(

                'name',

                'like',

                '%' . $request->search . '%'

            );
        }



        // ==========================================
        // STATUS FILTER
        // ==========================================

        if ($request->status) {

            $campaigns->where(

                'status',

                $request->status

            );
        }



        // ==========================================
        // TYPE FILTER
        // ==========================================

        if ($request->type) {

            $campaigns->where(

                'type',

                $request->type

            );
        }



        // ==========================================
        // PAGINATION
        // ==========================================

        $campaigns = $campaigns

            ->latest()

            ->paginate(20);



        // ==========================================
        // TRANSFORM
        // ==========================================

        $campaigns->getCollection()->transform(

            function ($campaign) {

                $deliveryRate = 0;

                $readRate = 0;



                if ($campaign->sent_count > 0) {

                    $deliveryRate = round(

                        (

                            $campaign->delivered_count

                            /

                            $campaign->sent_count

                        ) * 100,

                        2

                    );
                }



                if ($campaign->delivered_count > 0) {

                    $readRate = round(

                        (

                            $campaign->read_count

                            /

                            $campaign->delivered_count

                        ) * 100,

                        2

                    );
                }



                return [

                    'id' => $campaign->id,

                    'name' => $campaign->name,

                    'type' => $campaign->type,

                    'status' => $campaign->status,

                    'total_contacts' => $campaign->total_contacts,

                    'sent_count' => $campaign->sent_count,

                    'delivered_count' => $campaign->delivered_count,

                    'read_count' => $campaign->read_count,

                    'failed_count' => $campaign->failed_count,

                    'delivery_rate' => $deliveryRate,

                    'read_rate' => $readRate,

                    'scheduled_at' => $campaign->scheduled_at,

                    'created_at' => $campaign->created_at

                ];
            }

        );



        return response()->json([

            'success' => true,

            'data' => $campaigns

        ]);
    }

    public function show($id)
    {

        $campaign = Campaign::with([

            'template',

            'campaignContacts.contact'

        ])

            ->where(

                'tenant_id',

                auth()->user()->tenant_id

            )

            ->findOrFail($id);



        return response()->json([

            'success' => true,

            'data' => $campaign

        ]);
    }
}
