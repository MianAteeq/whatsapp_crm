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

/*
|--------------------------------------------------------------------------
| USE FRONTEND COMPONENT EXAMPLES IF AVAILABLE
|--------------------------------------------------------------------------
*/

$frontendBodyExample = null;

if (
    isset($request->components) &&
    is_array($request->components)
) {

    foreach ($request->components as $component) {

        if (
            isset($component['type']) &&
            strtoupper($component['type']) === 'BODY'
        ) {

            // BODY TEXT
            if (!empty($component['text'])) {
                $request['body'] = $component['text'];
            }

            // BODY EXAMPLE
            if (
                isset($component['example']['body_text'])
            ) {

                $frontendBodyExample =
                    $component['example']['body_text'];
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

if (
    isset($request->components) &&
    is_array($request->components)
) {

    foreach ($request->components as $component) {

        if (
            isset($component['type']) &&
            strtoupper($component['type']) === 'HEADER'
        ) {

            $headerType = strtoupper($component['format']);

            $header = [
                'type'   => 'HEADER',
                'format' => $headerType
            ];

            /*
            |--------------------------------------------------------------------------
            | TEXT HEADER
            |--------------------------------------------------------------------------
            */

            if ($headerType === 'TEXT') {

                $header['text'] = $component['text'];

                if (
                    !empty($component['example']['header_text'])
                ) {

                    $header['example'] = [
                        'header_text' =>
                            $component['example']['header_text']
                    ];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | MEDIA HEADER
            |--------------------------------------------------------------------------
            */

            elseif (
                in_array(
                    $headerType,
                    ['IMAGE', 'VIDEO', 'DOCUMENT']
                )
            ) {

                if (
                    !empty(
                        $component['example']['header_handle'][0]
                    )
                ) {

                    $header['example'] = [
                        'header_handle' => [
                            $component['example']['header_handle'][0]
                        ]
                    ];
                }
            }

            $components[] = $header;
        }
    }
}

/*
|--------------------------------------------------------------------------
| BODY
|--------------------------------------------------------------------------
*/

$body = [
    'type' => 'BODY',
    'text' => $request->body
];

/*
|--------------------------------------------------------------------------
| BODY EXAMPLES
|--------------------------------------------------------------------------
|
| Priority:
| 1. components[].example.body_text
| 2. samples.body_text
|
*/

if ($frontendBodyExample) {

    $body['example'] = [
        'body_text' => $frontendBodyExample
    ];
}

elseif ($request->filled('samples.body_text')) {

    $bodyExamples = [];

    foreach ($request->samples['body_text'] as $sample) {

        if (is_array($sample)) {
            $bodyExamples[] = $sample;
        } else {
            $bodyExamples[] = [(string) $sample];
        }
    }

    $body['example'] = [
        'body_text' => $bodyExamples
    ];
}

$components[] = $body;

/*
|--------------------------------------------------------------------------
| FOOTER
|--------------------------------------------------------------------------
*/

if ($request->filled('footer')) {

    $components[] = [
        'type' => 'FOOTER',
        'text' => $request->footer
    ];
}

/*
|--------------------------------------------------------------------------
| BUTTONS
|--------------------------------------------------------------------------
*/

if ($request->filled('buttons')) {

    $buttons = [];

    foreach ($request->buttons as $button) {

        // URL BUTTON
        if ($button['type'] === 'URL') {

            $buttonData = [
                'type' => 'URL',
                'text' => $button['text'],
                'url'  => $button['url']
            ];

            if (!empty($button['example'])) {

                $buttonData['example'] = [
                    $button['example']
                ];
            }

            $buttons[] = $buttonData;
        }

        // PHONE BUTTON
        elseif ($button['type'] === 'PHONE_NUMBER') {

            $buttons[] = [
                'type'         => 'PHONE_NUMBER',
                'text'         => $button['text'],
                'phone_number' => $button['phone_number']
            ];
        }

        // QUICK REPLY
        elseif ($button['type'] === 'QUICK_REPLY') {

            $buttons[] = [
                'type' => 'QUICK_REPLY',
                'text' => $button['text']
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

/*
|--------------------------------------------------------------------------
| FINAL PAYLOAD
|--------------------------------------------------------------------------
*/

$payload = [
    'name'       => strtolower($request->name),
    'category'   => strtoupper($request->category),
    'language'   => $request->language ?? 'en_US',
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
