<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\CampaignContact;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendCampaignJob implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;



    public $campaignId;



    /**
     * Create a new job instance.
     */
    public function __construct($campaignId)
    {

        $this->campaignId = $campaignId;
    }



    /**
     * Execute the job.
     */
    public function handle(): void
    {

        // ==========================================
        // FIND CAMPAIGN
        // ==========================================

        $campaign = Campaign::with([

            'template',

            'campaignContacts.contact'

        ])->find($this->campaignId);



        if (!$campaign) {

            return;
        }



        // ==========================================
        // UPDATE STATUS
        // ==========================================

        $campaign->update([

            'status' => 'running',

            'started_at' => now()

        ]);



        // ==========================================
        // WHATSAPP SETTINGS
        // ==========================================

        $setting = WhatsappSetting::where(

            'tenant_id',

            $campaign->tenant_id

        )->first();



        if (!$setting) {

            return;
        }



        // ==========================================
        // TEMPLATE
        // ==========================================

        $template = $campaign->template;



        if (!$template) {

            return;
        }



        // ==========================================
        // LOOP CONTACTS
        // ==========================================

        foreach ($campaign->campaignContacts as $campaignContact) {

            try {

                // ==========================================
                // CONTACT
                // ==========================================

                $contact = $campaignContact->contact;



                if (!$contact) {

                    continue;
                }



                // ==========================================
                // CREATE/FIND CONVERSATION
                // ==========================================

                $conversation = Conversation::firstOrCreate(

                    [

                        'tenant_id' => $campaign->tenant_id,

                        'contact_id' => $contact->id

                    ],

                    [

                        'wa_id' => $contact->phone,

                        'last_message' => '',

                        'last_message_at' => now()

                    ]

                );



                // ==========================================
                // TEMPLATE COMPONENTS
                // ==========================================

                $components = [];



                // ==========================================
                // SEND TEMPLATE REQUEST
                // ==========================================

                $payload = [

                    'messaging_product' => 'whatsapp',

                    'to' => $contact->phone,

                    'type' => 'template',

                    'template' => [

                        'name' => $template->name,

                        'language' => [

                            'code' => $template->language

                        ],

                        'components' => $components

                    ]

                ];



                // ==========================================
                // SEND MESSAGE
                // ==========================================

                $response = Http::withToken(

                    $setting->access_token

                )

                    ->post(

                        "https://graph.facebook.com/v19.0/{$setting->phone_number_id}/messages",

                        $payload

                    )

                    ->json();



                // ==========================================
                // SUCCESS
                // ==========================================

                if (isset($response['messages'][0]['id'])) {

                    // ==========================================
                    // MESSAGE ID
                    // ==========================================

                    $messageId =

                        $response['messages'][0]['id'];



                    // ==========================================
                    // STORE MESSAGE
                    // ==========================================

                    $message = Message::create([

                        'tenant_id' => $campaign->tenant_id,
                        'campaign_id' => $campaign->id,

                        'is_campaign' => true,

                        'conversation_id' => $conversation->id,

                        'message_id' => $messageId,

                        'direction' => 'outgoing',

                        'message' => '[Campaign] ' . $campaign->name,

                        'type' => 'template',

                        'status' => 'sent',

                        'payload' => $response

                    ]);

                    WhatsappMessageLog::create([

                        'tenant_id' => $campaign->tenant_id,

                        'campaign_id' => $campaign->id,

                        'contact_id' => $contact->id,

                        'message_id' => $messageId,

                        'template_name' => $template->name,

                        'recipient' => $contact->phone,

                        'status' => 'sent',

                        'sent_at' => now(),

                        'payload' => json_encode($response)

                    ]);



                    // ==========================================
                    // UPDATE CAMPAIGN CONTACT
                    // ==========================================

                    $campaignContact->update([

                        'status' => 'sent',

                        'message_id' => $messageId,

                        'sent_at' => now()

                    ]);



                    // ==========================================
                    // UPDATE CONVERSATION
                    // ==========================================

                    $conversation->update([

                        'last_message' => '[Campaign] ' . $campaign->name,

                        'last_message_at' => now()

                    ]);



                    // ==========================================
                    // UPDATE CAMPAIGN COUNTS
                    // ==========================================

                    $campaign->increment('sent_count');
                }



                // ==========================================
                // FAILED
                // ==========================================

                else {

                    $campaignContact->update([

                        'status' => 'failed'

                    ]);



                    $campaign->increment('failed_count');
                }



                // ==========================================
                // RATE LIMIT
                // ==========================================

                sleep(1);
            } catch (\Exception $e) {

                // ==========================================
                // FAILED
                // ==========================================

                $campaignContact->update([

                    'status' => 'failed'

                ]);



                $campaign->increment('failed_count');
            }
        }



        // ==========================================
        // COMPLETE CAMPAIGN
        // ==========================================

        $campaign->update([

            'status' => 'completed',

            'completed_at' => now()

        ]);
    }
}
