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
}
