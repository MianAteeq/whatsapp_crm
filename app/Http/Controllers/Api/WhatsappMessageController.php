<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsappMessageController extends Controller
{


    public function send(Request $request)
    {

        $request->validate([

            'conversation_id' => 'required',

            'message' => 'required|string'

        ]);




        // ==========================================
        // FIND CONVERSATION
        // ==========================================

        $conversation = Conversation::where(

            'tenant_id',
            auth()->user()->tenant_id

        )->findOrFail(
            $request->conversation_id
        );




        // ==========================================
        // CONTACT
        // ==========================================

        $contact = $conversation->contact;




        // ==========================================
        // WHATSAPP SETTINGS
        // ==========================================

        $setting = WhatsappSetting::where(

            'tenant_id',
            auth()->user()->tenant_id

        )->first();




        // ==========================================
        // SEND TO META
        // ==========================================

        $response = Http::withToken(

            $setting->access_token

        )

            ->post(

                "https://graph.facebook.com/v19.0/{$setting->phone_number_id}/messages",

                [

                    'messaging_product' => 'whatsapp',

                    'to' => $contact->phone,

                    'type' => 'text',

                    'text' => [

                        'body' => $request->message

                    ]

                ]

            )

            ->json();




        // ==========================================
        // STORE MESSAGE
        // ==========================================

        Message::create([

            'tenant_id' => auth()->user()->tenant_id,

            'conversation_id' => $conversation->id,

            'message_id' => $response['messages'][0]['id'] ?? null,

            'direction' => 'outgoing',

            'message' => $request->message,

            'type' => 'text',

            'status' => 'sent',

            'payload' => $response

        ]);




        // ==========================================
        // UPDATE CONVERSATION
        // ==========================================

        $conversation->update([

            'last_message' => $request->message,

            'last_message_at' => now()

        ]);




        return response()->json([

            'success' => true,

            'response' => $response

        ]);
    }
}
