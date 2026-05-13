<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsappTemplateController extends Controller
{
    public function sync()
    {

        $setting = WhatsappSetting::where(

            'tenant_id',
            auth()->user()->tenant_id

        )->first();




        // ======================================
        // FETCH TEMPLATES
        // ======================================

        $response = Http::withToken(

            $setting->access_token

        )

            ->get(

                "https://graph.facebook.com/v19.0/{$setting->business_account_id}/message_templates"

            )

            ->json();




        // ======================================
        // STORE TEMPLATES
        // ======================================

        foreach ($response['data'] ?? [] as $template) {

            WhatsappTemplate::updateOrCreate(

                [

                    'tenant_id' => auth()->user()->tenant_id,

                    'template_id' => $template['id']

                ],

                [

                    'name' => $template['name'],

                    'category' => $template['category'] ?? null,

                    'language' => $template['language'] ?? null,

                    'status' => $template['status'] ?? null,

                    'components' => $template['components'] ?? []

                ]

            );
        }




        return response()->json([

            'success' => true,

            'response' => $response

        ]);
    }

    public function index()
    {

        $templates = WhatsappTemplate::where(

            'tenant_id',
            auth()->user()->tenant_id

        )

            ->latest()

            ->paginate(20);




        return response()->json([

            'success' => true,

            'data' => $templates

        ]);
    }



public function store(Request $request)
{
    // ======================================
    // VALIDATION
    // ======================================

    $request->validate([
        'name' => 'required|string',
        'category' => 'required|string',
        'language' => 'required|string',
        'body' => 'required|string',
    ]);

    // ======================================
    // SETTINGS
    // ======================================

    $setting = WhatsappSetting::where(
        'tenant_id',
        auth()->user()->tenant_id
    )->first();

    if (!$setting) {
        return response()->json([
            'success' => false,
            'message' => 'WhatsApp settings not found'
        ], 404);
    }

    // ======================================
    // COMPONENTS
    // ======================================

    $components = [];

    // ======================================
    // HEADER
    // ======================================

    if ($request->filled('header')) {

        $headerType = strtoupper($request->header['type']);

        $header = [
            'type'   => 'HEADER',
            'format' => $headerType
        ];

        // TEXT HEADER
        if ($headerType === 'TEXT') {

            $header['text'] = $request->header['text'];

            if (!empty($request->header['examples'])) {

                $header['example'] = [
                    'header_text' => $request->header['examples']
                ];
            }
        }

        // MEDIA HEADER
        elseif (in_array($headerType, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {

            $header['example'] = [
                'header_handle' => [
                    $request->header['media_handle']
                ]
            ];
        }

        $components[] = $header;
    }

    // ======================================
    // BODY
    // ======================================

    $body = [
        'type' => 'BODY',
        'text' => $request->body
    ];

    // BODY VARIABLES EXAMPLE
    if ($request->filled('samples.body_text')) {

        $body['example'] = [
            'body_text' => $request->samples['body_text']
        ];
    }

    $components[] = $body;

    // ======================================
    // FOOTER
    // ======================================

    if ($request->filled('footer')) {

        $components[] = [
            'type' => 'FOOTER',
            'text' => $request->footer
        ];
    }

    // ======================================
    // BUTTONS
    // ======================================

    if ($request->filled('buttons')) {

        $buttons = [];

        foreach ($request->buttons as $button) {

            // URL BUTTON
            if ($button['type'] === 'URL') {

                $buttons[] = [
                    'type' => 'URL',
                    'text' => $button['text'],
                    'url'  => $button['url']
                ];
            }

            // PHONE BUTTON
            elseif ($button['type'] === 'PHONE_NUMBER') {

                $buttons[] = [
                    'type'         => 'PHONE_NUMBER',
                    'text'         => $button['text'],
                    'phone_number' => $button['phone_number']
                ];
            }
        }

        if (!empty($buttons)) {

            $components[] = [
                'type'    => 'BUTTONS',
                'buttons' => $buttons
            ];
        }
    }

    // ======================================
    // TEMPLATE NAME
    // ======================================

    $templateName = $request->name;

    // ======================================
    // FINAL PAYLOAD
    // ======================================

    $components = $request['components'];

    $payload = [
        'name'       => $templateName,
        'category'   => strtoupper($request->category),
        'language'   => $request->language,
        'components' => $components
    ];

    // ======================================
    // LOG PAYLOAD
    // ======================================

    Log::info('WhatsApp Template Payload', $payload);

    // ======================================
    // SEND TO META
    // ======================================

    $response = Http::withToken(
        $setting->access_token
    )->post(
        "https://graph.facebook.com/v19.0/{$setting->business_account_id}/message_templates",
        $payload
    );

    $responseData = $response->json();

    // ======================================
    // LOG RESPONSE
    // ======================================

    Log::info('WhatsApp Template Response', $responseData);

    // ======================================
    // HANDLE META ERRORS
    // ======================================

    if (isset($responseData['error'])) {

        return response()->json([
            'success' => false,
            'message' => $responseData['error']['message'] ?? 'Meta API Error',
            'error'   => $responseData['error'],
            'payload' => $payload
        ], 422);
    }

    // ======================================
    // STORE TEMPLATE
    // ======================================

    $template = WhatsappTemplate::create([
        'tenant_id'  => auth()->user()->tenant_id,
        'template_id'=> $responseData['id'] ?? null,
        'name'       => $templateName,
        'category'   => strtoupper($request->category),
        'language'   => $request->language,
        'status'     => 'PENDING',
        'components' => $components
    ]);

    // ======================================
    // SUCCESS RESPONSE
    // ======================================

    return response()->json([
        'success' => true,
        'message' => 'Template submitted successfully',
        'data'    => $template,
        'response'=> $responseData
    ]);
}

    public function uploadMedia(Request $request)
    {
        $request->validate([
            'file_name'   => 'required',
            'file_type'   => 'required',
            'file_length' => 'required',
        ]);

        $setting = WhatsappSetting::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->first();

        $response = Http::withToken($setting->access_token)
            ->post(
                "https://graph.facebook.com/v25.0/1333790982005297/uploads",
                [],
                [
                    'query' => [
                        'file_name'   => $request->file_name,
                        'file_length' => $request->file_length,
                        'file_type'   => $request->file_type,
                    ]
                ]
            );

        return response()->json($response->json());
    }
}
