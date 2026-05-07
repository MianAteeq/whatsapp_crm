<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;

class WhatsappMessageController extends Controller
{
    public function send(Request $request)
{

    $request->validate([

        'conversation_id' => 'required',

        'message' => 'required'

    ]);


    $conversation = Conversation::findOrFail(
        $request->conversation_id
    );


    // send to meta
    // store outgoing message

}
}
