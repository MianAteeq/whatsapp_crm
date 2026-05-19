<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Message Controller
 * 
 * Handles sending text messages, media, and templates via WhatsApp API.
 * All operations are integrated with Meta's Graph API and stored in the local database.
 */
class WhatsappMessageController extends Controller
{
    /**
     * Send a text message via WhatsApp
     * 
     * Validates the conversation and message, sends it to Meta's WhatsApp API,
     * stores the message record, and updates the conversation's last message.
     * 
     * @param Request $request Contains conversation_id and message
     * @return \Illuminate\Http\JsonResponse Success response with Meta's message ID
     */
    public function send(Request $request)
    {
        // Validate input
        $request->validate([
            'conversation_id' => 'required',
            'message' => 'required|string',
        ]);

        // Retrieve conversation for authenticated tenant
        $conversation = Conversation::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->findOrFail($request->conversation_id);

        // Get contact from conversation
        $contact = $conversation->contact;

        // Retrieve WhatsApp settings for authenticated tenant
        $setting = WhatsappSetting::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->first();

        // Send text message to Meta Graph API
        $response = Http::withToken($setting->access_token)
            ->post(
                "https://graph.facebook.com/v19.0/{$setting->phone_number_id}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'to' => $contact->phone,
                    'type' => 'text',
                    'text' => [
                        'body' => $request->message,
                    ],
                ]
            )
            ->json();

        // Store message record in database
        Message::create([
            'tenant_id' => auth()->user()->tenant_id,
            'conversation_id' => $conversation->id,
            'message_id' => $response['messages'][0]['id'] ?? null,
            'direction' => 'outgoing',
            'message' => $request->message,
            'type' => 'text',
            'status' => 'sent',
            'payload' => $response,
        ]);

        // Update conversation with latest message info
        $conversation->update([
            'last_message' => $request->message,
            'last_message_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'response' => $response,
        ]);
    }

    /**
     * Send a media message via WhatsApp
     * 
     * Handles file uploads, detects media type (image, video, audio, document),
     * uploads to Meta, and sends as a media message. Stores the file locally and in database.
     * 
     * @param Request $request Contains conversation_id and file
     * @return \Illuminate\Http\JsonResponse Success/failure response
     */
    public function sendMedia(Request $request)
    {
        // Validate input
        $request->validate([
            'conversation_id' => 'required',
            'file' => 'required|file|max:20480',
        ]);

        // Retrieve conversation for authenticated tenant
        $conversation = Conversation::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->findOrFail($request->conversation_id);

        // Get contact from conversation
        $contact = $conversation->contact;

        // Retrieve WhatsApp settings for authenticated tenant
        $setting = WhatsappSetting::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->first();

        // Get uploaded file
        $file = $request->file('file');

        // Store file locally in public storage
        $path = $file->store('whatsapp-media', 'public');
        $fileUrl = asset('storage/' . $path);

        // Extract file details
        $mimeType = $file->getMimeType();
        $fileName = $file->getClientOriginalName();

        // Detect media type from MIME type
        $type = 'document';
        if (str_contains($mimeType, 'image')) {
            $type = 'image';
        } elseif (str_contains($mimeType, 'video')) {
            $type = 'video';
        } elseif (str_contains($mimeType, 'audio')) {
            $type = 'audio';
        }

        // Upload media file to Meta's WhatsApp API
        $uploadResponse = Http::withToken($setting->access_token)
            ->attach(
                'file',
                file_get_contents($file->getRealPath()),
                $fileName
            )
            ->post(
                "https://graph.facebook.com/v19.0/{$setting->phone_number_id}/media",
                [
                    'messaging_product' => 'whatsapp',
                ]
            )
            ->json();

        // Check if media upload failed
        if (isset($uploadResponse['error'])) {
            return response()->json([
                'success' => false,
                'message' => 'Media upload failed',
                'error' => $uploadResponse,
            ], 422);
        }

        // Extract media ID from upload response
        $mediaId = $uploadResponse['id'];

        // Build payload for media message
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $contact->phone,
            'type' => $type,
            $type => [
                'id' => $mediaId,
            ],
        ];

        // Send media message to Meta's WhatsApp API
        $response = Http::withToken($setting->access_token)
            ->post(
                "https://graph.facebook.com/v19.0/{$setting->phone_number_id}/messages",
                $payload
            )
            ->json();

        // Store message record in database
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
            'payload' => $response,
        ]);

        // Update conversation with latest message info
        $conversation->update([
            'last_message' => '📎 Attachment',
            'last_message_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Media message sent successfully',
            'response' => $response,
        ]);
    }
    
    public function sendTemplate(Request $request)
    {
        $request->validate([
            'template_id' => 'required',
            'phone'       => 'required',
            'parameters'  => 'nullable|array',
            'components'  => 'nullable|array',
        ]);

        $template = WhatsappTemplate::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->findOrFail($request->template_id);

        $setting = WhatsappSetting::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->first();

        $storedComponents = is_array($template->components)
            ? $template->components
            : json_decode($template->components, true);

        $components = [];

        /*
    |--------------------------------------------------------------------------
    | HEADER COMPONENT
    |--------------------------------------------------------------------------
    */

        $header = collect($storedComponents)
            ->firstWhere('type', 'HEADER');

        if ($header) {

            /*
        |--------------------------------------------------------------------------
        | IMAGE HEADER
        |--------------------------------------------------------------------------
        */

            if (($header['format'] ?? '') === 'IMAGE') {

                $imageLink =
                    $request->header_image
                    ?? ($header['example']['header_handle'][0] ?? null);

                if ($imageLink) {

                    $components[] = [
                        'type' => 'header',
                        'parameters' => [
                            [
                                'type'  => 'image',
                                'image' => [
                                    'link' => $imageLink,
                                ],
                            ],
                        ],
                    ];
                }
            }

            /*
        |--------------------------------------------------------------------------
        | VIDEO HEADER
        |--------------------------------------------------------------------------
        */

            if (($header['format'] ?? '') === 'VIDEO') {

                $videoLink =
                    $request->header_video
                    ?? ($header['example']['header_handle'][0] ?? null);

                if ($videoLink) {

                    $components[] = [
                        'type' => 'header',
                        'parameters' => [
                            [
                                'type'  => 'video',
                                'video' => [
                                    'link' => $videoLink,
                                ],
                            ],
                        ],
                    ];
                }
            }

            /*
        |--------------------------------------------------------------------------
        | DOCUMENT HEADER
        |--------------------------------------------------------------------------
        */

            if (($header['format'] ?? '') === 'DOCUMENT') {

                $documentLink =
                    $request->header_document
                    ?? ($header['example']['header_handle'][0] ?? null);

                if ($documentLink) {

                    $components[] = [
                        'type' => 'header',
                        'parameters' => [
                            [
                                'type'     => 'document',
                                'document' => [
                                    'link'     => $documentLink,
                                    'filename' => 'document.pdf',
                                ],
                            ],
                        ],
                    ];
                }
            }
        }

        /*
    |--------------------------------------------------------------------------
    | BODY COMPONENT
    |--------------------------------------------------------------------------
    */

        $body = collect($storedComponents)
            ->firstWhere('type', 'BODY');

        if ($body) {

            $bodyParameters = [];

            /*
        |--------------------------------------------------------------------------
        | 1. USE COMPONENTS FROM REQUEST
        |--------------------------------------------------------------------------
        */

            if (!empty($request->components)) {

                foreach ($request->components as $component) {

                    if (($component['type'] ?? '') === 'body') {

                        $bodyParameters = $component['parameters'] ?? [];
                    }
                }
            }

            /*
        |--------------------------------------------------------------------------
        | 2. USE SIMPLE PARAMETERS ARRAY
        |--------------------------------------------------------------------------
        */ elseif (!empty($request->parameters)) {

                foreach ($request->parameters as $parameter) {

                    $bodyParameters[] = [
                        'type' => 'text',
                        'text' => $parameter,
                    ];
                }
            }

            /*
        |--------------------------------------------------------------------------
        | 3. FALLBACK TO TEMPLATE EXAMPLES
        |--------------------------------------------------------------------------
        */ else {

                $examples = $body['example']['body_text'][0] ?? [];

                foreach ($examples as $parameter) {

                    $bodyParameters[] = [
                        'type' => 'text',
                        'text' => $parameter,
                    ];
                }
            }

            if (!empty($bodyParameters)) {

                $components[] = [
                    'type'       => 'body',
                    'parameters' => $bodyParameters,
                ];
            }
        }

        /*
    |--------------------------------------------------------------------------
    | FINAL PAYLOAD
    |--------------------------------------------------------------------------
    */

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $request->phone,
            'type'              => 'template',
            'template'          => [
                'name'       => $template->name,
                'language'   => [
                    'code' => $template->language ?? 'en_US',
                ],
                'components' => $components,
            ],
        ];

        $response = Http::withToken($setting->access_token)
            ->post(
                "https://graph.facebook.com/v19.0/{$setting->phone_number_id}/messages",
                $payload
            )
            ->json();

        /*
    |--------------------------------------------------------------------------
    | CONTACT
    |--------------------------------------------------------------------------
    */

    $wa_id = $response['contacts'][0]['wa_id'] ?? null;

        $contact = Contact::firstOrCreate(
            [
                'tenant_id' => auth()->user()->tenant_id,
                'phone'     => $request->phone,
               
            ],
            [
                'name' => $request->phone,
            ]
        );

        /*
    |--------------------------------------------------------------------------
    | CONVERSATION
    |--------------------------------------------------------------------------
    */

        $conversation = Conversation::firstOrCreate(
            [
                'tenant_id' => auth()->user()->tenant_id,
                'contact_id' => $contact->id,
                'wa_id'     => $wa_id,
            ],
            [
                'last_message' => '[Template] ' . $template->name,
                'last_message_at' => now(),
            ]
        );

        /*
    |--------------------------------------------------------------------------
    | SAVE MESSAGE
    |--------------------------------------------------------------------------
    */

        Message::create([
            'tenant_id'       => auth()->user()->tenant_id,
            'conversation_id' => $conversation->id,
            'message_id'      => $response['messages'][0]['id'] ?? null,
            'direction'       => 'outgoing',
            'message'         => '[Template] ' . $template->name,
            'type'            => 'template',
            'status'          => isset($response['messages'])
                ? 'sent'
                : 'failed',
            'payload'         => $response,
        ]);

        return response()->json([
            'success'  => isset($response['messages']),
            'payload'  => $payload,
            'response' => $response,
        ]);
    }
}
