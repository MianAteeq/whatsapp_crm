<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WhatsappWebhookController extends Controller
{
     // ==========================================
    // VERIFY WEBHOOK
    // ==========================================

    public function verify(Request $request)
    {

        $verify_token = env('WHATSAPP_WEBHOOK_VERIFY_TOKEN');


        if (

            $request->hub_mode == 'subscribe'

            &&

            $request->hub_verify_token == $verify_token

        ) {

            return response(
                $request->hub_challenge,
                200
            );

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

        return response()->json([

            'success' => true

        ]);

    }
}
