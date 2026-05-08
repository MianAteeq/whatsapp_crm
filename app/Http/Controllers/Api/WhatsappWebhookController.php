<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappSetting;
use Illuminate\Http\Request;

class WhatsappWebhookController extends Controller
{
    // ==========================================
    // VERIFY WEBHOOK
    // ==========================================

    public function verify(Request $request)
    {

        $verify_token = env('WHATSAPP_WEBHOOK_VERIFY_TOKEN');


        if (

            $request->hub_mode == 'subscribe'

            &&

            $request->hub_verify_token == $verify_token

        ) {

            return response(
                $request->hub_challenge,
                200
            );
        }


        return response()->json([

            'success' => false,

            'message' => 'Invalid verification token'

        ], 403);
    }




    // ==========================================
    // RECEIVE EVENTS
    // ==========================================

    public function handle(Request $request)
    {

        \Log::info('WhatsApp Webhook:', $request->all());



        // ==========================================
        // GET ENTRY
        // ==========================================

        $entry = $request->entry[0] ?? null;

        if (!$entry) {

            return response()->json([
                'success' => false
            ]);
        }



        // ==========================================
        // GET CHANGE
        // ==========================================

        $change = $entry['changes'][0] ?? null;

        if (!$change) {

            return response()->json([
                'success' => false
            ]);
        }



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
        // INCOMING MESSAGES
        // ==========================================

        if (isset($value['messages'])) {

            foreach ($value['messages'] as $incomingMessage) {

                // ======================================
                // CONTACT INFO
                // ======================================

                $waId = $incomingMessage['from'];

                $messageText = $incomingMessage['text']['body'] ?? '';



                // ======================================
                // FIND CONTACT
                // ======================================

                $contact = Contact::firstOrCreate(

                    [

                        'tenant_id' => $setting->tenant_id,

                        'phone' => $waId

                    ],

                    [

                        'name' => $waId

                    ]

                );



                // ======================================
                // FIND OR CREATE CONVERSATION
                // ======================================

                $conversation = Conversation::firstOrCreate(

                    [

                        'tenant_id' => $setting->tenant_id,

                        'contact_id' => $contact->id

                    ],

                    [

                        'wa_id' => $waId

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

                    'type' => $incomingMessage['type'] ?? 'text',

                    'status' => 'received',

                    'payload' => $incomingMessage

                ]);



                // ======================================
                // UPDATE CONVERSATION
                // ======================================

                $conversation->update([

                    'last_message' => $messageText,

                    'last_message_at' => now(),

                    'unread_count' => $conversation->unread_count + 1

                ]);

                broadcast(
                    new MessageReceived($message)
                )->toOthers();
            }
        }



        // ==========================================
        // MESSAGE STATUS
        // ==========================================

        if (isset($value['statuses'])) {

            foreach ($value['statuses'] as $status) {

                Message::where(

                    'message_id',

                    $status['id']

                )->update([

                    'status' => $status['status']

                ]);
            }
        }





        return response()->json([

            'success' => true

        ]);
    }
}
