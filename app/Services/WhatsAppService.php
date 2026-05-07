<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\WhatsappSetting;

class WhatsAppService
{

    public function sendText($tenantId, $to, $message)
    {

        $setting = WhatsappSetting::where(
            'tenant_id',
            $tenantId
        )->first();


        if (!$setting) {

            throw new \Exception('WhatsApp not connected');

        }


        return Http::withToken($setting->access_token)

            ->post(

                "https://graph.facebook.com/v19.0/{$setting->phone_number_id}/messages",

                [

                    'messaging_product' => 'whatsapp',

                    'to' => $to,

                    'type' => 'text',

                    'text' => [

                        'body' => $message

                    ]

                ]

            )

            ->json();

    }

}