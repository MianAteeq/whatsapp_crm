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
}
