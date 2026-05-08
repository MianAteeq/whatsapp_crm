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

    public function sendMedia(Request $request)
    {

        $request->validate([

            'conversation_id' => 'required',

            'file' => 'required|file|max:20480'

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
        // FILE
        // ==========================================

        $file = $request->file('file');



        // ==========================================
        // STORE FILE
        // ==========================================

        $path = $file->store(

            'whatsapp-media',

            'public'

        );



        $fileUrl = asset(
            'storage/' . $path
        );



        // ==========================================
        // FILE DETAILS
        // ==========================================

        $mimeType = $file->getMimeType();

        $fileName = $file->getClientOriginalName();



        // ==========================================
        // DETECT TYPE
        // ==========================================

        $type = 'document';


        if (str_contains($mimeType, 'image')) {

            $type = 'image';
        } elseif (str_contains($mimeType, 'video')) {

            $type = 'video';
        } elseif (str_contains($mimeType, 'audio')) {

            $type = 'audio';
        }



        // ==========================================
        // UPLOAD MEDIA TO META
        // ==========================================

        $uploadResponse = Http::withToken(

            $setting->access_token

        )

            ->attach(

                'file',

                file_get_contents($file->getRealPath()),

                $fileName

            )

            ->post(

                "https://graph.facebook.com/v19.0/{$setting->phone_number_id}/media",

                [

                    'messaging_product' => 'whatsapp'

                ]

            )

            ->json();




        // ==========================================
        // CHECK UPLOAD ERROR
        // ==========================================

        if (isset($uploadResponse['error'])) {

            return response()->json([

                'success' => false,

                'message' => 'Media upload failed',

                'error' => $uploadResponse

            ], 422);
        }




        // ==========================================
        // MEDIA ID
        // ==========================================

        $mediaId = $uploadResponse['id'];




        // ==========================================
        // SEND MEDIA MESSAGE
        // ==========================================

        $payload = [

            'messaging_product' => 'whatsapp',

            'to' => $contact->phone,

            'type' => $type,

            $type => [

                'id' => $mediaId

            ]

        ];




        $response = Http::withToken(

            $setting->access_token

        )

            ->post(

                "https://graph.facebook.com/v19.0/{$setting->phone_number_id}/messages",

                $payload

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

            'message' => null,

            'type' => $type,

            'status' => 'sent',

            'media_url' => $fileUrl,

            'media_type' => $type,

            'mime_type' => $mimeType,

            'file_name' => $fileName,

            'payload' => $response

        ]);




        // ==========================================
        // UPDATE CONVERSATION
        // ==========================================

        $conversation->update([

            'last_message' => '📎 Attachment',

            'last_message_at' => now()

        ]);




        // ==========================================
        // RESPONSE
        // ==========================================

        return response()->json([

            'success' => true,

            'message' => 'Media message sent successfully',

            'response' => $response

        ]);
    }
}
