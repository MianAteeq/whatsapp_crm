<?php

namespace App\Http\Controllers\Api;

use App\Events\CampaignUpdated;
use App\Events\MessageReceived;
use App\Events\MessageStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class WhatsappWebhookController extends Controller
{
    // ==========================================
    // VERIFY WEBHOOK
    // ==========================================

    public function verify(Request $request)
    {
        $verify_token = env('WHATSAPP_WEBHOOK_VERIFY_TOKEN');

        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if (
            $mode === 'subscribe' &&
            $token === $verify_token
        ) {
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid verification token'
        ], 403);
    }




    // ==========================================
    // RECEIVE EVENTS
    // ==========================================

    // public function handle(Request $request)
    // {

    //     \Log::info('WhatsApp Webhook:', $request->all());



    //     // ==========================================
    //     // GET ENTRY
    //     // ==========================================

    //     $entry = $request->entry[0] ?? null;

    //     if (!$entry) {

    //         return response()->json([
    //             'success' => false
    //         ]);
    //     }



    //     // ==========================================
    //     // GET CHANGE
    //     // ==========================================

    //     $change = $entry['changes'][0] ?? null;

    //     if (!$change) {

    //         return response()->json([
    //             'success' => false
    //         ]);
    //     }



    //     $value = $change['value'] ?? [];



    //     // ==========================================
    //     // PHONE NUMBER ID
    //     // ==========================================

    //     $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;



    //     // ==========================================
    //     // FIND TENANT
    //     // ==========================================

    //     $setting = WhatsappSetting::where(
    //         'phone_number_id',
    //         $phoneNumberId
    //     )->first();



    //     if (!$setting) {

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Tenant not found'
    //         ]);
    //     }



    //     // ==========================================
    //     // INCOMING MESSAGES
    //     // ==========================================

    //     if (isset($value['messages'])) {

    //         foreach ($value['messages'] as $incomingMessage) {

    //             // ======================================
    //             // MESSAGE TYPE
    //             // ======================================

    //             $messageType = $incomingMessage['type'] ?? 'text';



    //             // ======================================
    //             // DEFAULT VALUES
    //             // ======================================

    //             $messageText = null;

    //             $mediaId = null;

    //             $mimeType = null;

    //             $fileName = null;

    //             $mediaUrl = null;



    //             // ======================================
    //             // HANDLE TEXT
    //             // ======================================

    //             if ($messageType === 'text') {

    //                 $messageText = $incomingMessage['text']['body'] ?? '';
    //             }



    //             // ======================================
    //             // HANDLE IMAGE
    //             // ======================================

    //             elseif ($messageType === 'image') {

    //                 $mediaId = $incomingMessage['image']['id'] ?? null;

    //                 $mimeType = $incomingMessage['image']['mime_type'] ?? null;

    //                 $messageText = '📷 Image';
    //             }



    //             // ======================================
    //             // HANDLE DOCUMENT
    //             // ======================================

    //             elseif ($messageType === 'document') {

    //                 $mediaId = $incomingMessage['document']['id'] ?? null;

    //                 $mimeType = $incomingMessage['document']['mime_type'] ?? null;

    //                 $fileName = $incomingMessage['document']['filename'] ?? null;

    //                 $messageText = '📄 Document';
    //             }



    //             // ======================================
    //             // HANDLE VIDEO
    //             // ======================================

    //             elseif ($messageType === 'video') {

    //                 $mediaId = $incomingMessage['video']['id'] ?? null;

    //                 $mimeType = $incomingMessage['video']['mime_type'] ?? null;

    //                 $messageText = '🎥 Video';
    //             }



    //             // ======================================
    //             // HANDLE AUDIO
    //             // ======================================

    //             elseif ($messageType === 'audio') {

    //                 $mediaId = $incomingMessage['audio']['id'] ?? null;

    //                 $mimeType = $incomingMessage['audio']['mime_type'] ?? null;

    //                 $messageText = '🎵 Audio';
    //             }



    //             // ======================================
    //             // DOWNLOAD MEDIA FROM META
    //             // ======================================

    //             if ($mediaId) {

    //                 // ======================================
    //                 // GET MEDIA INFO
    //                 // ======================================

    //                 $mediaResponse = Http::withToken(

    //                     $setting->access_token

    //                 )

    //                     ->get(

    //                         "https://graph.facebook.com/v19.0/{$mediaId}"

    //                     )

    //                     ->json();



    //                 // ======================================
    //                 // TEMP MEDIA URL
    //                 // ======================================

    //                 $tempMediaUrl = $mediaResponse['url'] ?? null;



    //                 // ======================================
    //                 // DOWNLOAD FILE
    //                 // ======================================

    //                 if ($tempMediaUrl) {

    //                     $mediaContent = Http::withToken(

    //                         $setting->access_token

    //                     )

    //                         ->get($tempMediaUrl)

    //                         ->body();



    //                     // ======================================
    //                     // FILE EXTENSION
    //                     // ======================================

    //                     $extension = match ($mimeType) {

    //                         'image/jpeg' => 'jpg',

    //                         'image/png' => 'png',

    //                         'video/mp4' => 'mp4',

    //                         'audio/ogg' => 'ogg',

    //                         'application/pdf' => 'pdf',

    //                         default => 'bin'
    //                     };



    //                     // ======================================
    //                     // FILE NAME
    //                     // ======================================

    //                     $storedFileName =

    //                         'whatsapp-media/' .

    //                         time() .

    //                         '_' .

    //                         uniqid() .

    //                         '.' .

    //                         $extension;



    //                     // ======================================
    //                     // STORE FILE
    //                     // ======================================

    //                     Storage::disk('public')->put(

    //                         $storedFileName,

    //                         $mediaContent

    //                     );



    //                     // ======================================
    //                     // PERMANENT URL
    //                     // ======================================

    //                     $mediaUrl = asset(

    //                         'storage/' . $storedFileName

    //                     );
    //                 }
    //             }



    //             // ======================================
    //             // CONTACT INFO
    //             // ======================================

    //             $waId = $incomingMessage['from'];



    //             // ======================================
    //             // FIND CONTACT
    //             // ======================================

    //             $contact = Contact::firstOrCreate(

    //                 [

    //                     'tenant_id' => $setting->tenant_id,

    //                     'phone' => $waId

    //                 ],

    //                 [

    //                     'name' => $waId

    //                 ]

    //             );



    //             // ======================================
    //             // FIND OR CREATE CONVERSATION
    //             // ======================================

    //             $conversation = Conversation::firstOrCreate(

    //                 [

    //                     'tenant_id' => $setting->tenant_id,

    //                     'contact_id' => $contact->id

    //                 ],

    //                 [

    //                     'wa_id' => $waId

    //                 ]

    //             );



    //             // ======================================
    //             // STORE MESSAGE
    //             // ======================================

    //             $message = Message::create([

    //                 'tenant_id' => $setting->tenant_id,

    //                 'conversation_id' => $conversation->id,

    //                 'message_id' => $incomingMessage['id'] ?? null,

    //                 'direction' => 'incoming',

    //                 'message' => $messageText,

    //                 'type' => $messageType,

    //                 'status' => 'received',

    //                 'media_url' => $mediaUrl,

    //                 'media_type' => $messageType,

    //                 'mime_type' => $mimeType,

    //                 'file_name' => $fileName,

    //                 'payload' => $incomingMessage

    //             ]);



    //             // ======================================
    //             // UPDATE CONVERSATION
    //             // ======================================

    //             $conversation->update([

    //                 'last_message' => $messageText,

    //                 'last_message_at' => now(),

    //                 'unread_count' => $conversation->unread_count + 1

    //             ]);



    //             // ======================================
    //             // REALTIME BROADCAST
    //             // ======================================

    //             broadcast(

    //                 new MessageReceived($message)

    //             )->toOthers();
    //         }
    //     }



    //     // ==========================================
    //     // MESSAGE STATUS
    //     // ==========================================

    //     if (isset($value['statuses'])) {

    //         foreach ($value['statuses'] as $status) {

    //             Message::where(

    //                 'message_id',

    //                 $status['id']

    //             )->update([

    //                 'status' => $status['status']

    //             ]);
    //         }
    //     }





    //     return response()->json([

    //         'success' => true

    //     ]);
    // }

    public function handle(Request $request)
    {

        \Log::info('WhatsApp Webhook:', $request->all());



        // ==========================================
        // GET ENTRY
        // ==========================================

        $entry = $request->entry[0] ?? null;

        if (!$entry) {

            return response()->json([

                'success' => false,

                'message' => 'Entry not found'

            ]);
        }



        // ==========================================
        // GET CHANGE
        // ==========================================

        $change = $entry['changes'][0] ?? null;

        if (!$change) {

            return response()->json([

                'success' => false,

                'message' => 'Change not found'

            ]);
        }



        // ==========================================
        // VALUE
        // ==========================================

        $value = $change['value'] ?? [];



        // ==========================================
        // PHONE NUMBER ID
        // ==========================================

        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;



        // ==========================================
        // FIND TENANT
        // ==========================================

        $setting = WhatsappSetting::where(

            'phone_number_id',

            $phoneNumberId

        )->first();



        if (!$setting) {

            return response()->json([

                'success' => false,

                'message' => 'Tenant not found'

            ]);
        }



        // ==========================================
        // CONTACT PROFILES
        // ==========================================

        $contactProfiles = [];



        if (isset($value['contacts'])) {

            foreach ($value['contacts'] as $contactData) {

                $waId = $contactData['wa_id'] ?? null;

                $profileName =

                    $contactData['profile']['name']

                    ?? $waId;



                $contactProfiles[$waId] = $profileName;
            }
        }



        // ==========================================
        // INCOMING MESSAGES
        // ==========================================

        if (isset($value['messages'])) {

            foreach ($value['messages'] as $incomingMessage) {

                try {

                    // ======================================
                    // DUPLICATE CHECK
                    // ======================================

                    $alreadyExists = Message::where(

                        'message_id',

                        $incomingMessage['id'] ?? null

                    )->exists();



                    if ($alreadyExists) {

                        continue;
                    }



                    // ======================================
                    // MESSAGE TYPE
                    // ======================================

                    $messageType =

                        $incomingMessage['type']

                        ?? 'text';



                    // ======================================
                    // DEFAULT VALUES
                    // ======================================

                    $messageText = null;

                    $mediaId = null;

                    $mimeType = null;

                    $fileName = null;

                    $mediaUrl = null;

                    $payload = $incomingMessage;



                    // ======================================
                    // TEXT MESSAGE
                    // ======================================

                    if ($messageType === 'text') {

                        $messageText =

                            $incomingMessage['text']['body']

                            ?? '';
                    }



                    // ======================================
                    // IMAGE
                    // ======================================

                    elseif ($messageType === 'image') {

                        $mediaId =

                            $incomingMessage['image']['id']

                            ?? null;



                        $mimeType =

                            $incomingMessage['image']['mime_type']

                            ?? null;



                        $messageText = '📷 Image';
                    }



                    // ======================================
                    // VIDEO
                    // ======================================

                    elseif ($messageType === 'video') {

                        $mediaId =

                            $incomingMessage['video']['id']

                            ?? null;



                        $mimeType =

                            $incomingMessage['video']['mime_type']

                            ?? null;



                        $messageText = '🎥 Video';
                    }



                    // ======================================
                    // AUDIO
                    // ======================================

                    elseif ($messageType === 'audio') {

                        $mediaId =

                            $incomingMessage['audio']['id']

                            ?? null;



                        $mimeType =

                            $incomingMessage['audio']['mime_type']

                            ?? null;



                        $messageText = '🎵 Audio';
                    }



                    // ======================================
                    // DOCUMENT
                    // ======================================

                    elseif ($messageType === 'document') {

                        $mediaId =

                            $incomingMessage['document']['id']

                            ?? null;



                        $mimeType =

                            $incomingMessage['document']['mime_type']

                            ?? null;



                        $fileName =

                            $incomingMessage['document']['filename']

                            ?? null;



                        $messageText = '📄 Document';
                    }



                    // ======================================
                    // REACTION
                    // ======================================

                    elseif ($messageType === 'reaction') {

                        $emoji =

                            $incomingMessage['reaction']['emoji']

                            ?? '👍';



                        $messageText =

                            'Reaction: ' . $emoji;
                    }



                    // ======================================
                    // LOCATION
                    // ======================================

                    elseif ($messageType === 'location') {

                        $latitude =

                            $incomingMessage['location']['latitude']

                            ?? null;



                        $longitude =

                            $incomingMessage['location']['longitude']

                            ?? null;



                        $messageText =

                            '📍 Location: '

                            . $latitude

                            . ', '

                            . $longitude;
                    }



                    // ======================================
                    // CONTACT CARD
                    // ======================================

                    elseif ($messageType === 'contacts') {

                        $messageText =

                            '👤 Shared Contact';
                    }



                    // ======================================
                    // DOWNLOAD MEDIA
                    // ======================================

                    if ($mediaId) {

                        // ======================================
                        // GET MEDIA INFO
                        // ======================================

                        $mediaResponse = Http::withToken(

                            $setting->access_token

                        )

                            ->get(

                                "https://graph.facebook.com/v19.0/{$mediaId}"

                            )

                            ->json();



                        $tempMediaUrl =

                            $mediaResponse['url']

                            ?? null;



                        // ======================================
                        // DOWNLOAD FILE
                        // ======================================

                        if ($tempMediaUrl) {

                            $mediaContent = Http::withToken(

                                $setting->access_token

                            )

                                ->get($tempMediaUrl)

                                ->body();



                            // ======================================
                            // EXTENSION
                            // ======================================

                            $extension = match ($mimeType) {

                                'image/jpeg' => 'jpg',

                                'image/png' => 'png',

                                'image/webp' => 'webp',

                                'video/mp4' => 'mp4',

                                'audio/ogg' => 'ogg',

                                'audio/mpeg' => 'mp3',

                                'application/pdf' => 'pdf',

                                default => 'bin'
                            };



                            // ======================================
                            // FILE NAME
                            // ======================================

                            $storedFileName =

                                'whatsapp-media/' .

                                time() .

                                '_' .

                                uniqid() .

                                '.' .

                                $extension;



                            // ======================================
                            // STORE FILE
                            // ======================================

                            Storage::disk('public')->put(

                                $storedFileName,

                                $mediaContent

                            );



                            // ======================================
                            // FINAL URL
                            // ======================================

                            $mediaUrl = asset(

                                'storage/' . $storedFileName

                            );
                        }
                    }



                    // ======================================
                    // WHATSAPP USER
                    // ======================================

                    $waId =

                        $incomingMessage['from']

                        ?? null;



                    // ======================================
                    // PROFILE NAME
                    // ======================================

                    $profileName =

                        $contactProfiles[$waId]

                        ?? $waId;



                    // ======================================
                    // FIND CONTACT
                    // ======================================

                    $contact = Contact::firstOrCreate(

                        [

                            'tenant_id' => $setting->tenant_id,

                            'phone' => $waId

                        ],

                        [

                            'name' => $profileName

                        ]

                    );



                    // ======================================
                    // UPDATE NAME
                    // ======================================

                    if (

                        $profileName

                        &&

                        $contact->name !== $profileName

                    ) {

                        $contact->update([

                            'name' => $profileName

                        ]);
                    }



                    // ======================================
                    // CONVERSATION
                    // ======================================

                    $conversation = Conversation::firstOrCreate(

                        [

                            'tenant_id' => $setting->tenant_id,

                            'contact_id' => $contact->id

                        ],

                        [

                            'wa_id' => $waId,

                            'last_message' => '',

                            'last_message_at' => now(),

                            'unread_count' => 0

                        ]

                    );



                    // ======================================
                    // STORE MESSAGE
                    // ======================================

                    $message = Message::create([

                        'tenant_id' => $setting->tenant_id,

                        'conversation_id' => $conversation->id,

                        'message_id' => $incomingMessage['id'] ?? null,

                        'direction' => 'incoming',

                        'message' => $messageText,

                        'type' => $messageType,

                        'status' => 'received',

                        'media_url' => $mediaUrl,

                        'media_type' => $messageType,

                        'mime_type' => $mimeType,

                        'file_name' => $fileName,

                        'payload' => $payload

                    ]);



                    // ======================================
                    // UPDATE CONVERSATION
                    // ======================================

                    $conversation->update([

                        'last_message' => $messageText,

                        'last_message_at' => now(),

                        'unread_count' =>

                        $conversation->unread_count + 1

                    ]);



                    // ======================================
                    // REALTIME EVENT
                    // ======================================

                    broadcast(

                        new MessageReceived($message)

                    )->toOthers();

                    // ======================================
                    // AUTOMATIC RESPONSE SYSTEM
                    // ======================================
                    if ($conversation->is_auto_reply_active && !empty($setting->openai_key) && !empty($setting->company_prompt) && $messageType === 'text') {
                        try {
                            $openAiService = new \App\Services\OpenAiService();
                            $replyText = $openAiService->generateResponse(
                                $setting->openai_key,
                                $setting->company_prompt,
                                $conversation->id,
                                $messageText
                            );

                          \Log::info('WhatsApp replyText', [
    'replyText' => $replyText,
]);

                            $metaResponse = null;
                            if (!empty($setting->access_token) && !empty($setting->phone_number_id)) {
                                $metaResponse = \Illuminate\Support\Facades\Http::withToken($setting->access_token)
                                    ->post(
                                        "https://graph.facebook.com/v19.0/{$setting->phone_number_id}/messages",
                                        [
                                            'messaging_product' => 'whatsapp',
                                            'to' => $contact->phone,
                                            'type' => 'text',
                                            'text' => [
                                                'body' => $replyText,
                                            ],
                                        ]
                                    )
                                    ->json();
                            }

                            $autoReplyMsg = Message::create([
                                'tenant_id' => $setting->tenant_id,
                                'conversation_id' => $conversation->id,
                                'message_id' => $metaResponse['messages'][0]['id'] ?? 'auto_' . uniqid(),
                                'direction' => 'outgoing',
                                'message' => $replyText,
                                'type' => 'text',
                                'status' => 'sent',
                                'payload' => $metaResponse,
                            ]);

                            $conversation->update([
                                'last_message' => $replyText,
                                'last_message_at' => now(),
                            ]);

                            broadcast(new MessageReceived($autoReplyMsg));

                        } catch (\Exception $autoReplyEx) {
                            \Log::error('Auto-Reply Webhook Error: ' . $autoReplyEx->getMessage());
                        }
                    }
                } catch (\Exception $e) {

                    \Log::error(

                        'Webhook Incoming Message Error',

                        [

                            'error' => $e->getMessage(),

                            'payload' => $incomingMessage

                        ]

                    );
                }
            }
        }



        // ==========================================
        // MESSAGE STATUSES
        // ==========================================

        if (isset($value['statuses'])) {

            foreach ($value['statuses'] as $status) {

                try {

                    // ======================================
                    // STATUS DATA
                    // ======================================

                    $messageId =

                        $status['id']

                        ?? null;



                    $messageStatus =

                        $status['status']

                        ?? null;



                    // ======================================
                    // FIND MESSAGE
                    // ======================================

                    $message = Message::where(

                        'message_id',

                        $messageId

                    )->first();



                    if (!$message) {

                        continue;
                    }



                    // ======================================
                    // UPDATE MESSAGE STATUS
                    // ======================================

                    $message->update([

                        'status' => $messageStatus

                    ]);



                    // ======================================
                    // READ TIME
                    // ======================================

                    if ($messageStatus === 'read') {

                        $message->update([

                            'read_at' => now()

                        ]);
                    }



                    // ======================================
                    // CAMPAIGN CONTACT
                    // ======================================

                    if ($message->is_campaign) {

                        // ======================================
                        // FIND CAMPAIGN CONTACT
                        // ======================================

                        $campaignContact = CampaignContact::where(

                            'message_id',

                            $messageId

                        )->first();



                        if ($campaignContact) {

                            // ======================================
                            // PREVIOUS STATUS
                            // ======================================

                            $previousStatus = $campaignContact->status;



                            // ======================================
                            // UPDATE CONTACT STATUS
                            // ======================================

                            $campaignContact->update([

                                'status' => $messageStatus

                            ]);



                            // ======================================
                            // FIND CAMPAIGN
                            // ======================================

                            $campaign = Campaign::find(

                                $campaignContact->campaign_id

                            );



                            if ($campaign) {

                                // ======================================
                                // DELIVERED
                                // ======================================

                                if (

                                    $messageStatus === 'delivered'

                                    &&

                                    $previousStatus !== 'delivered'

                                ) {

                                    $campaignContact->update([

                                        'delivered_at' => now()

                                    ]);



                                    $campaign->increment(

                                        'delivered_count'

                                    );
                                }



                                // ======================================
                                // READ
                                // ======================================

                                if (

                                    $messageStatus === 'read'

                                    &&

                                    $previousStatus !== 'read'

                                ) {

                                    $campaignContact->update([

                                        'read_at' => now()

                                    ]);



                                    $campaign->increment(

                                        'read_count'

                                    );
                                }



                                // ======================================
                                // FAILED
                                // ======================================

                                if (

                                    $messageStatus === 'failed'

                                    &&

                                    $previousStatus !== 'failed'

                                ) {

                                    $campaign->increment(

                                        'failed_count'

                                    );
                                }



                                // ======================================
                                // DELIVERY RATE
                                // ======================================

                                $deliveryRate = 0;



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



                                // ======================================
                                // READ RATE
                                // ======================================

                                $readRate = 0;



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



                                // ======================================
                                // REFRESH CAMPAIGN
                                // ======================================

                                $campaign->refresh();



                                // ======================================
                                // REALTIME CAMPAIGN EVENT
                                // ======================================

                                broadcast(

                                    new CampaignUpdated([

                                        'campaign' => $campaign,

                                        'delivery_rate' => $deliveryRate,

                                        'read_rate' => $readRate

                                    ])

                                )->toOthers();
                            }
                        }
                    }

                    $messageLog = WhatsappMessageLog::where(

                        'message_id',

                        $messageId

                    )->first();



                    if ($messageLog) {

                        $updateData = [

                            'status' => $messageStatus

                        ];



                        // ======================================
                        // DELIVERED
                        // ======================================

                        if ($messageStatus === 'delivered') {

                            $updateData['delivered_at'] = now();
                        }



                        // ======================================
                        // READ
                        // ======================================

                        if ($messageStatus === 'read') {

                            $updateData['read_at'] = now();



                            // AUTO DELIVERED FIX
                            if (!$messageLog->delivered_at) {

                                $updateData['delivered_at'] = now();
                            }
                        }



                        // ======================================
                        // FAILED
                        // ======================================

                        if ($messageStatus === 'failed') {

                            $updateData['failed_at'] = now();



                            $updateData['error_message'] = json_encode(

                                $status['errors'] ?? []

                            );
                        }



                        // ======================================
                        // UPDATE
                        // ======================================

                        $messageLog->update($updateData);
                    }





                    // ======================================
                    // REALTIME MESSAGE STATUS
                    // ======================================

                    broadcast(

                        new MessageStatusUpdated($message)

                    )->toOthers();
                } catch (\Exception $e) {

                    \Log::error(

                        'Webhook Status Error',

                        [

                            'error' => $e->getMessage(),

                            'payload' => $status

                        ]

                    );
                }
            }
        }



        // ==========================================
        // TEMPLATE STATUS UPDATE
        // ==========================================

        if (

            isset($value['message_template_id'])

            &&

            isset($value['event'])

        ) {

            WhatsappTemplate::where(

                'template_id',

                $value['message_template_id']

            )->update([

                'status' => strtoupper(

                    $value['event']

                )

            ]);
        }



        // ==========================================
        // FINAL RESPONSE
        // ==========================================

        return response()->json([

            'success' => true

        ]);
    }
}
