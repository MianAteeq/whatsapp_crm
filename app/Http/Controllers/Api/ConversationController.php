<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index()
    {

        $conversations = Conversation::with('contact')

            ->where(
                'tenant_id',
                auth()->user()->tenant_id
            )

            ->latest('last_message_at')

            ->paginate(20);


        return response()->json($conversations);
    }

    public function messages($id)
    {

        $messages = Message::where(
            'conversation_id',
            $id
        )

            ->latest()

            ->paginate(50);


        return response()->json($messages);
    }
}
