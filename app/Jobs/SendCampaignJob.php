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
use Illuminate\Support\Facades\Log;

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
        // ======================================================
        // FIND CAMPAIGN
        // ======================================================

        $campaign = Campaign::with([

            'template',

            'campaignContacts.contact'

        ])->find($this->campaignId);



        if (!$campaign) {

            return;
        }



        // ======================================================
        // UPDATE STATUS
        // ======================================================

        $campaign->update([

            'status' => 'running',

            'started_at' => now()

        ]);



        // ======================================================
        // SETTINGS
        // ======================================================

        $setting = WhatsappSetting::where(

            'tenant_id',

            $campaign->tenant_id

        )->first();



        if (!$setting) {

            return;
        }



        // ======================================================
        // TEMPLATE
        // ======================================================

        $template = $campaign->template;



        if (!$template) {

            return;
        }



        // ======================================================
        // TEMPLATE COMPONENTS
        // ======================================================

        $templateComponents =

            is_array($template->components)

            ? $template->components

            : json_decode(
                $template->components,
                true
            );



        // ======================================================
        // TEMPLATE VARIABLES
        // ======================================================

        $templateVariables =

            is_array($template->variables)

            ? $template->variables

            : json_decode(
                $template->variables,
                true
            );



        // ======================================================
        // LOOP CONTACTS
        // ======================================================

        foreach ($campaign->campaignContacts as $campaignContact) {

            try {

                // ==================================================
                // CONTACT
                // ==================================================

                $contact = $campaignContact->contact;



                if (!$contact) {

                    continue;
                }



                // ==================================================
                // CONVERSATION
                // ==================================================

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



                // ==================================================
                // WHATSAPP COMPONENTS
                // ==================================================

                $components = [];



                // ==================================================
                // LOOP TEMPLATE COMPONENTS
                // ==================================================

                foreach ($templateComponents as $component) {

                    $componentType = strtoupper(

                        $component['type'] ?? ''

                    );



                    // ==================================================
                    // HEADER
                    // ==================================================

                    if ($componentType === 'HEADER') {

                        $format = strtoupper(

                            $component['format'] ?? ''

                        );



                        // ==============================================
                        // IMAGE
                        // ==============================================

                        if (

                            $format === 'IMAGE'

                            &&

                            !empty($template->media_url)

                        ) {

                            $components[] = [

                                'type' => 'header',

                                'parameters' => [

                                    [

                                        'type' => 'image',

                                        'image' => [

                                            'link' =>

                                            $template->media_url

                                        ]

                                    ]

                                ]

                            ];
                        }



                        // ==============================================
                        // VIDEO
                        // ==============================================

                        elseif (

                            $format === 'VIDEO'

                            &&

                            !empty($template->media_url)

                        ) {

                            $components[] = [

                                'type' => 'header',

                                'parameters' => [

                                    [

                                        'type' => 'video',

                                        'video' => [

                                            'link' =>

                                            $template->media_url

                                        ]

                                    ]

                                ]

                            ];
                        }



                        // ==============================================
                        // DOCUMENT
                        // ==============================================

                        elseif (

                            $format === 'DOCUMENT'

                            &&

                            !empty($template->media_url)

                        ) {

                            $components[] = [

                                'type' => 'header',

                                'parameters' => [

                                    [

                                        'type' => 'document',

                                        'document' => [

                                            'link' =>

                                            $template->media_url,



                                            'filename' =>

                                            basename(
                                                $template->media_url
                                            )

                                        ]

                                    ]

                                ]

                            ];
                        }



                        // ==============================================
                        // TEXT HEADER VARIABLES
                        // ==============================================

                        elseif ($format === 'TEXT') {

                            preg_match_all(

                                '/{{(.*?)}}/',

                                $component['text'] ?? '',

                                $matches

                            );



                            $parameters = [];



                            foreach (

                                $matches[1]

                                as $variableNumber

                            ) {

                                // ======================================
                                // FIELD
                                // ======================================

                                $field =

                                    $templateVariables[$variableNumber]

                                    ?? null;



                                // ======================================
                                // VALUE
                                // ======================================

                                $value = null;



                                if ($field) {

                                    $value = data_get(

                                        $contact,

                                        $field

                                    );
                                }



                                // ======================================
                                // FALLBACK
                                // ======================================

                                if (

                                    $value === null

                                    ||

                                    $value === ''

                                ) {

                                    $value =

                                        '{{'

                                        .

                                        $variableNumber

                                        .

                                        '}}';
                                }



                                $parameters[] = [

                                    'type' => 'text',

                                    'text' => (string) $value

                                ];
                            }



                            if (!empty($parameters)) {

                                $components[] = [

                                    'type' => 'header',

                                    'parameters' => $parameters

                                ];
                            }
                        }
                    }



                    // ==================================================
                    // BODY VARIABLES
                    // ==================================================

                    if ($componentType === 'BODY') {

                        preg_match_all(

                            '/{{(.*?)}}/',

                            $component['text'] ?? '',

                            $matches

                        );



                        $parameters = [];



                        foreach (

                            $matches[1]

                            as $variableNumber

                        ) {

                            // ==========================================
                            // FIELD
                            // ==========================================

                            $field =

                                $templateVariables[$variableNumber]

                                ?? null;



                            // ==========================================
                            // VALUE
                            // ==========================================

                            $value = null;



                            if ($field) {

                                $value = data_get(

                                    $contact,

                                    $field

                                );
                            }



                            // ==========================================
                            // FALLBACK
                            // ==========================================

                            if (

                                $value === null

                                ||

                                $value === ''

                            ) {

                                $value =

                                    '{{'

                                    .

                                    $variableNumber

                                    .

                                    '}}';
                            }



                            $parameters[] = [

                                'type' => 'text',

                                'text' => (string) $value

                            ];
                        }



                        if (!empty($parameters)) {

                            $components[] = [

                                'type' => 'body',

                                'parameters' => $parameters

                            ];
                        }
                    }
                }



                // ==================================================
                // FINAL PAYLOAD
                // ==================================================

                $payload = [

                    'messaging_product' => 'whatsapp',

                    'to' => $contact->phone,

                    'type' => 'template',

                    'template' => [

                        'name' =>

                        $template->meta_template_name

                            ??

                            $template->name,



                        'language' => [

                            'code' =>

                            $template->language

                        ],



                        'components' => $components

                    ]

                ];



                // ==================================================
                // LOG PAYLOAD
                // ==================================================

                Log::info(

                    'Campaign Template Payload',

                    $payload

                );



                // ==================================================
                // SEND MESSAGE
                // ==================================================

                $response = Http::withToken(

                    $setting->access_token

                )->post(

                    "https://graph.facebook.com/v19.0/{$setting->phone_number_id}/messages",

                    $payload

                )->json();



                // ==================================================
                // LOG RESPONSE
                // ==================================================

                Log::info(

                    'Campaign Template Response',

                    $response

                );



                // ==================================================
                // SUCCESS
                // ==================================================

                if (

                    isset($response['messages'][0]['id'])

                ) {

                    $messageId =

                        $response['messages'][0]['id'];



                    // ==============================================
                    // STORE MESSAGE
                    // ==============================================

                    Message::create([

                        'tenant_id' => $campaign->tenant_id,

                        'campaign_id' => $campaign->id,

                        'is_campaign' => true,

                        'conversation_id' => $conversation->id,

                        'message_id' => $messageId,

                        'direction' => 'outgoing',

                        'message' => '[Campaign] ' . $campaign->name,

                        'type' => 'template',

                        'status' => 'sent',

                        'payload' => json_encode($response)

                    ]);



                    // ==============================================
                    // MESSAGE LOG
                    // ==============================================

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



                    // ==============================================
                    // UPDATE CAMPAIGN CONTACT
                    // ==============================================

                    $campaignContact->update([

                        'status' => 'sent',

                        'message_id' => $messageId,

                        'sent_at' => now()

                    ]);



                    // ==============================================
                    // UPDATE CONVERSATION
                    // ==============================================

                    $conversation->update([

                        'last_message' => '[Campaign] ' . $campaign->name,

                        'last_message_at' => now()

                    ]);



                    // ==============================================
                    // UPDATE COUNTS
                    // ==============================================

                    $campaign->increment(

                        'sent_count'

                    );
                }



                // ==================================================
                // FAILED
                // ==================================================

                else {

                    $campaignContact->update([

                        'status' => 'failed'

                    ]);



                    $campaign->increment(

                        'failed_count'

                    );
                }



                // ==================================================
                // RATE LIMIT
                // ==================================================

                sleep(1);
            } catch (\Exception $e) {

                // ==================================================
                // FAILED
                // ==================================================

                $campaignContact->update([

                    'status' => 'failed'

                ]);



                $campaign->increment(

                    'failed_count'

                );



                Log::error(

                    'Campaign Send Error',

                    [

                        'campaign_id' => $campaign->id,

                        'contact_id' => $campaignContact->contact_id,

                        'error' => $e->getMessage()

                    ]

                );
            }
        }



        // ======================================================
        // COMPLETE CAMPAIGN
        // ======================================================

        $campaign->update([

            'status' => 'completed',

            'completed_at' => now()

        ]);
    }
}
