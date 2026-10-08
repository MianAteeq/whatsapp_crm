<?php

namespace App\Services\Ai;

use App\Models\EmailSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeepSeekEmailService
{
    protected string $endpoint = 'https://api.deepseek.com/chat/completions';
    protected string $model = 'deepseek-chat';

    /**
     * Resolve the active DeepSeek API key (Universal platform key from .env).
     */
    public static function resolveApiKey(int $tenantId, ?string $requestKey = null): ?string
    {
        // Universal platform key from .env / config
        $universalKey = config('services.deepseek.api_key') ?: env('DEEPSEEK_API_KEY');
        if (!empty($universalKey)) {
            return trim($universalKey);
        }

        if (!empty($requestKey) && !str_contains($requestKey, '•••')) {
            return trim($requestKey);
        }

        $setting = EmailSetting::where('tenant_id', $tenantId)->first();
        if (!empty($setting?->deepseek_api_key)) {
            return $setting->deepseek_api_key;
        }

        return null;
    }

    /**
     * Generate an email using DeepSeek Chat (DeepSeek-V3)
     *
     * @param string $apiKey
     * @param array{
     *     topic: string,
     *     purpose?: string,
     *     tone?: string,
     *     target_audience?: string,
     *     business_context?: string,
     *     call_to_action?: string,
     * } $params
     * @return array{
     *     success: bool,
     *     subject?: string,
     *     html_body?: string,
     *     text_body?: string,
     *     error?: string
     * }
     */
    public function generateEmail(string $apiKey, array $params): array
    {
        $topic = trim($params['topic'] ?? '');
        if (empty($topic)) {
            return [
                'success' => false,
                'error' => 'Please provide a topic or prompt for the email.',
            ];
        }

        $purpose = $params['purpose'] ?? 'Cold Outreach';
        $tone = $params['tone'] ?? 'Professional and Persuasive';
        $audience = $params['target_audience'] ?? 'Prospective Business Clients';
        $cta = $params['call_to_action'] ?? 'Schedule a quick 10-minute discovery call';

        $systemPrompt = <<<PROMPT
You are a world-class email copywriter specializing in outbound sales, B2B lead generation, and client nurturing.
Write compelling, high-converting emails that feel authentic, human, and relevant.

CRITICAL RULES:
1. ONLY use the following 2 personalization merge tags:
   - {{first_name | default: "there"}}
   - {{business_name}}
   DO NOT use {{city}}, {{industry}}, {{phone}}, {{website}}, or any other variable.
2. Keep the email concise (between 80 and 180 words), scannable, and focused on value proposition.
3. Avoid generic sales cliches and spammy buzzwords.
4. You MUST respond with ONLY valid JSON with no markdown wrapping, matching this exact schema:
{
  "subject": "Compelling subject line with {{business_name}} if relevant",
  "html_body": "<p>Opening line with {{first_name | default: 'there'}}...</p><p>Core value proposition regarding {{business_name}}...</p><p>Clear call to action...</p><p>Best regards,<br>{{from_name}}</p>",
  "text_body": "Plain text version of the email..."
}
PROMPT;

        $userPrompt = "Generate a {$purpose} email with a {$tone} tone.\n"
            . "Target Audience: {$audience}\n"
            . "Key Topic / Value Proposition: {$topic}\n"
            . "Desired Call To Action: {$cta}\n";

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(35)->post($this->endpoint, [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->status() === 401) {
                return [
                    'success' => false,
                    'error' => 'DeepSeek Authentication Failed: Invalid API Key. Please verify your DeepSeek API key in Email Settings.',
                ];
            }

            if ($response->status() === 429) {
                return [
                    'success' => false,
                    'error' => 'DeepSeek Rate Limit: Your DeepSeek API rate limit or quota has been reached.',
                ];
            }

            if (!$response->successful()) {
                Log::error('[DeepSeek] API Error: ' . $response->body());
                return [
                    'success' => false,
                    'error' => 'DeepSeek API Error (HTTP ' . $response->status() . '): ' . $response->body(),
                ];
            }

            $data = $response->json();
            $rawContent = $data['choices'][0]['message']['content'] ?? '{}';
            
            // Clean markdown code blocks if returned
            $rawContent = preg_replace('/^```(?:json)?\s*/i', '', trim($rawContent));
            $rawContent = preg_replace('/\s*```$/', '', $rawContent);

            $parsed = json_decode($rawContent, true);

            if (!is_array($parsed) || empty($parsed['subject']) || empty($parsed['html_body'])) {
                Log::warning('[DeepSeek] Malformed JSON response: ' . $rawContent);
                return [
                    'success' => true,
                    'subject' => 'Quick partnership question for {{business_name}}',
                    'html_body' => nl2br(htmlspecialchars($rawContent)),
                    'text_body' => $rawContent,
                ];
            }

            return [
                'success' => true,
                'subject' => $parsed['subject'],
                'html_body' => $parsed['html_body'],
                'text_body' => $parsed['text_body'] ?? strip_tags($parsed['html_body']),
            ];

        } catch (Throwable $e) {
            Log::error('[DeepSeek] Request Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'DeepSeek Connection Failed: ' . $e->getMessage(),
            ];
        }
    }
}
