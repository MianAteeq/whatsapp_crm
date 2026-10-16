<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\Message;

class OpenAiService
{
    /**
     * Resolve the active DeepSeek / AI API key (Universal platform key from .env / config for all vendors).
     */
    public static function resolveApiKey(?string $tenantKey = null): ?string
    {
        // 1. Check custom tenant key override if provided
        if (!empty($tenantKey) && !str_contains($tenantKey, '•') && $tenantKey !== 'CONFIGURED') {
            return trim($tenantKey);
        }

        // 2. Check universal DeepSeek platform API key
        $deepseekKey = config('services.deepseek.api_key') ?: env('DEEPSEEK_API_KEY');
        if (!empty($deepseekKey)) {
            return trim($deepseekKey);
        }

        // 3. Fallback to OpenAI API key if present
        $universalKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        if (!empty($universalKey)) {
            return trim($universalKey);
        }

        return null;
    }

    /**
     * Generate an AI response based on the conversation history and company prompt using DeepSeek.
     *
     * @param string|null $openAiKey
     * @param string $companyPrompt
     * @param int $conversationId
     * @param string $incomingMessageText
     * @return string
     */
    public function generateResponse(?string $openAiKey, string $companyPrompt, int $conversationId, string $incomingMessageText): string
    {
        $activeKey = !empty($openAiKey) && !str_contains($openAiKey, '•') ? $openAiKey : self::resolveApiKey($openAiKey);
        if (empty($activeKey)) {
            \Log::warning('[AI] No DeepSeek/AI API Key found (universal or vendor-level).');
            return 'Thank you for your message. We will get back to you shortly.';
        }

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

        // Configure DeepSeek API defaults
        $baseUrl = rtrim(config('services.deepseek.base_url') ?: env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'), '/');
        $model = config('services.deepseek.model') ?: env('DEEPSEEK_MODEL', 'deepseek-chat');

        // Fallback to OpenAI configuration if only OpenAI key is configured
        if (empty(env('DEEPSEEK_API_KEY')) && !empty(env('OPENAI_API_KEY'))) {
            $baseUrl = rtrim(config('services.openai.base_url') ?: env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/');
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $activeKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'messages' => $messagesForAi,
                'temperature' => 0.7,
            ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content') ?? 'Sorry, I could not generate a response.';
            }

            \Log::error('[DeepSeek/AI] API Error: ' . $response->body());
            return 'Thank you for your message. We will get back to you shortly.';
        } catch (\Exception $e) {
            \Log::error('[DeepSeek/AI] Request Exception: ' . $e->getMessage());
            return 'Thank you for your message. We will get back to you shortly.';
        }
    }
}
