<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\Message;

class OpenAiService
{
    /**
     * Generate an AI response based on the conversation history and company prompt.
     *
     * @param string $openAiKey
     * @param string $companyPrompt
     * @param int $conversationId
     * @param string $incomingMessageText
     * @return string
     */
    public function generateResponse(string $openAiKey, string $companyPrompt, int $conversationId, string $incomingMessageText): string
    {
        // Fetch recent messages for context (last 10 messages)
        $recentMessages = Message::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->reverse();

        $messagesForAi = [
            [
                'role' => 'system',
                'content' => "You are an AI assistant for a company. Here is the context/information about our company:\n\n" 
                           . $companyPrompt 
                           . "\n\nReply to the customer's message politely, professionally, and helpfully using only the company context provided. Keep the response concise, suitable for WhatsApp, and conversational. Do not make up any facts or information not included in the company prompt. If you do not know the answer or if the information is not provided in the context, politely inform them that you'll refer them to a human representative shortly."
            ]
        ];

        foreach ($recentMessages as $msg) {
            $role = $msg->direction === 'incoming' ? 'user' : 'assistant';
            $messagesForAi[] = [
                'role' => $role,
                'content' => $msg->message
            ];
        }

        // Add the current incoming message if not already present in history
        $hasCurrentMessage = false;
        foreach ($recentMessages as $msg) {
            if ($msg->message === $incomingMessageText && $msg->direction === 'incoming') {
                $hasCurrentMessage = true;
                break;
            }
        }

        if (!$hasCurrentMessage) {
            $messagesForAi[] = [
                'role' => 'user',
                'content' => $incomingMessageText
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $openAiKey,
                'Content-Type' => 'application/json',
            ])->timeout(12)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => $messagesForAi,
                'temperature' => 0.7,
            ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content') ?? 'Sorry, I could not generate a response.';
            }

            \Log::error('OpenAI API Error: ' . $response->body());
            return 'Thank you for your message. We will get back to you shortly.';
        } catch (\Exception $e) {
            \Log::error('OpenAI Request Exception: ' . $e->getMessage());
            return 'Thank you for your message. We will get back to you shortly.';
        }
    }
}
