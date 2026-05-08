<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request)
    {

        $conversations = Conversation::with([

            'contact'

        ])

            ->where(
                'tenant_id',
                auth()->user()->tenant_id
            )

            ->latest('last_message_at')

            ->paginate(20);


        return response()->json([

            'success' => true,

            'data' => $conversations

        ]);
    }

    public function messages($id)
    {

        $conversation = Conversation::where(

            'tenant_id',
            auth()->user()->tenant_id

        )->findOrFail($id);




        $messages = Message::where(

            'conversation_id',
            $conversation->id

        )

            ->orderBy('created_at', 'asc')

            ->paginate(50);




        return response()->json([

            'success' => true,

            'data' => $messages

        ]);
    }

    public function markRead($id)
    {

        // ==========================================
        // FIND CONVERSATION
        // ==========================================

        $conversation = Conversation::where(

            'tenant_id',
            auth()->user()->tenant_id

        )->findOrFail($id);




        // ==========================================
        // RESET UNREAD COUNT
        // ==========================================

        $conversation->update([

            'unread_count' => 0

        ]);




        // ==========================================
        // RESPONSE
        // ==========================================

        return response()->json([

            'success' => true,

            'message' => 'Conversation marked as read',

            'data' => $conversation

        ]);
    }
}
