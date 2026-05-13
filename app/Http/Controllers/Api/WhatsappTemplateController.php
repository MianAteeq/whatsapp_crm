<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

        $request->validate([

            'name' => 'required',

            'category' => 'required',

            'language' => 'required',

            'body' => 'required'

        ]);



        // ======================================
        // SETTINGS
        // ======================================

        $setting = WhatsappSetting::where(

            'tenant_id',
            auth()->user()->tenant_id

        )->first();



        // ======================================
        // COMPONENTS
        // ======================================

        $components = [];



        // ======================================
        // HEADER
        // ======================================

        // ======================================
        // HEADER
        // ======================================

        if ($request->header) {

            $headerType = strtoupper(
                $request->header['type']
            );



            $header = [

                'type' => 'HEADER',

                'format' => $headerType

            ];



            // ======================================
            // TEXT HEADER
            // ======================================

            if ($headerType === 'TEXT') {

                $header['text'] = $request->header['text'];



                // HEADER VARIABLES EXAMPLE
                if (isset($request->header['examples'])) {

                    $header['example'] = [

                        'header_text' => $request->header['examples']

                    ];
                }
            }



            // ======================================
            // IMAGE HEADER
            // ======================================

            elseif ($headerType === 'IMAGE') {

                $header['example'] = [

                    'header_handle' => [

                        $request->header['media_handle']

                    ]

                ];
            }



            // ======================================
            // VIDEO HEADER
            // ======================================

            elseif ($headerType === 'VIDEO') {

                $header['example'] = [

                    'header_handle' => [

                        $request->header['media_handle']

                    ]

                ];
            }



            // ======================================
            // DOCUMENT HEADER
            // ======================================

            elseif ($headerType === 'DOCUMENT') {

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



        // ======================================
        // VARIABLE SAMPLES
        // ======================================

        if ($request->samples) {

            $body['example'] = [

                'body_text' => $request->samples['body_text']

            ];
        }



        $components[] = $body;



        // ======================================
        // FOOTER
        // ======================================

        if ($request->footer) {

            $components[] = [

                'type' => 'FOOTER',

                'text' => $request->footer

            ];
        }



        // ======================================
        // BUTTONS
        // ======================================

        if ($request->buttons) {

            $buttons = [];



            foreach ($request->buttons as $button) {

                if ($button['type'] === 'URL') {

                    $buttons[] = [

                        'type' => 'URL',

                        'text' => $button['text'],

                        'url' => $button['url']

                    ];
                } elseif ($button['type'] === 'PHONE_NUMBER') {

                    $buttons[] = [

                        'type' => 'PHONE_NUMBER',

                        'text' => $button['text'],

                        'phone_number' => $button['phone_number']

                    ];
                }
            }



            $components[] = [

                'type' => 'BUTTONS',

                'buttons' => $buttons

            ];
        }



        // ======================================
        // FINAL PAYLOAD
        // ======================================

        $payload = [

            'name' => strtolower($request->name),

            'category' => strtoupper($request->category),

            'language' => $request->language,

            'components' => $components

        ];



        // ======================================
        // SEND TO META
        // ======================================

        $response = Http::withToken(

            $setting->access_token

        )

            ->post(

                "https://graph.facebook.com/v19.0/{$setting->business_account_id}/message_templates",

                $payload

            )

            ->json();




        // ======================================
        // STORE TEMPLATE
        // ======================================

        $template = WhatsappTemplate::create([

            'tenant_id' => auth()->user()->tenant_id,

            'template_id' => $response['id'] ?? null,

            'name' => $request->name,

            'category' => $request->category,

            'language' => $request->language,

            'status' => 'PENDING',

            'components' => $components

        ]);




        return response()->json([

            'success' => true,

            'data' => $template,

            'response' => $response

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
