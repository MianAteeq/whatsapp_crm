<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSetting;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

class WhatsappSettingController extends Controller
{
    public function store(Request $request)
    {

        $request->validate([

            'access_token' => 'required',

            'phone_number_id' => 'required',

            'business_account_id' => 'required'

        ]);


        $setting = WhatsappSetting::updateOrCreate(

            [
                'tenant_id' => auth()->user()->tenant_id
            ],

            [

                'access_token' => $request->access_token,

                'phone_number_id' => $request->phone_number_id,

                'business_account_id' => $request->business_account_id,

                'is_connected' => true

            ]

        );


        return response()->json([

            'success' => true,

            'message' => 'WhatsApp API connected successfully',

            'data' => $setting

        ]);
    }

    public function show()
    {

        $setting = WhatsappSetting::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->first();


        return response()->json([

            'success' => true,

            'data' => $setting

        ]);
    }
    public function testConnection(
        Request $request,
        WhatsAppService $whatsAppService
    ) {

        $request->validate([

            'phone' => 'required'

        ]);


        $response = $whatsAppService->sendText(

            auth()->user()->tenant_id,

            $request->phone,

            'WhatsApp API connected successfully.'

        );


        return response()->json([

            'success' => true,

            'response' => $response

        ]);
    }
}
