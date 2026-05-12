<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappSetting;
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
   return $verify_token = env('WHATSAPP_WEBHOOK_VERIFY_TOKEN');

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
                // MESSAGE TYPE
                // ======================================

                $messageType = $incomingMessage['type'] ?? 'text';



                // ======================================
                // DEFAULT VALUES
                // ======================================

                $messageText = null;

                $mediaId = null;

                $mimeType = null;

                $fileName = null;

                $mediaUrl = null;



                // ======================================
                // HANDLE TEXT
                // ======================================

                if ($messageType === 'text') {

                    $messageText = $incomingMessage['text']['body'] ?? '';
                }



                // ======================================
                // HANDLE IMAGE
                // ======================================

                elseif ($messageType === 'image') {

                    $mediaId = $incomingMessage['image']['id'] ?? null;

                    $mimeType = $incomingMessage['image']['mime_type'] ?? null;

                    $messageText = '📷 Image';
                }



                // ======================================
                // HANDLE DOCUMENT
                // ======================================

                elseif ($messageType === 'document') {

                    $mediaId = $incomingMessage['document']['id'] ?? null;

                    $mimeType = $incomingMessage['document']['mime_type'] ?? null;

                    $fileName = $incomingMessage['document']['filename'] ?? null;

                    $messageText = '📄 Document';
                }



                // ======================================
                // HANDLE VIDEO
                // ======================================

                elseif ($messageType === 'video') {

                    $mediaId = $incomingMessage['video']['id'] ?? null;

                    $mimeType = $incomingMessage['video']['mime_type'] ?? null;

                    $messageText = '🎥 Video';
                }



                // ======================================
                // HANDLE AUDIO
                // ======================================

                elseif ($messageType === 'audio') {

                    $mediaId = $incomingMessage['audio']['id'] ?? null;

                    $mimeType = $incomingMessage['audio']['mime_type'] ?? null;

                    $messageText = '🎵 Audio';
                }



                // ======================================
                // DOWNLOAD MEDIA FROM META
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



                    // ======================================
                    // TEMP MEDIA URL
                    // ======================================

                    $tempMediaUrl = $mediaResponse['url'] ?? null;



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
                        // FILE EXTENSION
                        // ======================================

                        $extension = match ($mimeType) {

                            'image/jpeg' => 'jpg',

                            'image/png' => 'png',

                            'video/mp4' => 'mp4',

                            'audio/ogg' => 'ogg',

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
                        // PERMANENT URL
                        // ======================================

                        $mediaUrl = asset(

                            'storage/' . $storedFileName

                        );
                    }
                }



                // ======================================
                // CONTACT INFO
                // ======================================

                $waId = $incomingMessage['from'];



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

                    'type' => $messageType,

                    'status' => 'received',

                    'media_url' => $mediaUrl,

                    'media_type' => $messageType,

                    'mime_type' => $mimeType,

                    'file_name' => $fileName,

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



                // ======================================
                // REALTIME BROADCAST
                // ======================================

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
