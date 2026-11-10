<?php

namespace App\Services\Social;

use App\Services\OpenAiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;

class SocialAiCopilotService
{
    protected string $endpoint;
    protected string $model;

    public function __construct()
    {
        $this->endpoint = config('services.deepseek.base_url', 'https://api.deepseek.com') . '/chat/completions';
        $this->model = config('services.deepseek.model', 'deepseek-chat');
    }

    /**
     * Resolve API key using platform settings or fallback
     */
    protected function getApiKey(): string
    {
        $key = OpenAiService::resolveApiKey();
        if (empty($key)) {
            throw new Exception('AI API key is not configured. Please configure DEEPSEEK_API_KEY or OPENAI_API_KEY in .env.');
        }
        return $key;
    }

    /**
     * Generate 3 high-converting caption variations and relevant hashtags
     */
    public function generateCaptions(array $params): array
    {
        $topic = trim($params['topic'] ?? '');
        if (empty($topic)) {
            throw new Exception('Please provide a topic or prompt for the social post.');
        }

        $platform = $params['platform'] ?? 'all';
        $tone = $params['tone'] ?? 'engaging';
        $goal = $params['goal'] ?? 'engagement';
        $language = $params['language'] ?? 'en';
        $includeEmojis = $params['include_emojis'] ?? true;
        $includeHashtags = $params['include_hashtags'] ?? true;
        $includeCta = $params['include_cta'] ?? true;

        $langPrompt = match ($language) {
            'ar' => 'CRITICAL: Write ALL output in natural, professional, native Arabic (العربية).',
            'ur' => 'CRITICAL: Write ALL output in authentic Urdu (اردو).',
            'es' => 'CRITICAL: Write ALL output in fluent Spanish (Español).',
            'fr' => 'CRITICAL: Write ALL output in fluent French (Français).',
            'de' => 'CRITICAL: Write ALL output in natural German (Deutsch).',
            'pt' => 'CRITICAL: Write ALL output in natural Portuguese (Português).',
            'hi' => 'CRITICAL: Write ALL output in natural Hindi (हिन्दी).',
            'it' => 'CRITICAL: Write ALL output in natural Italian (Italiano).',
            default => 'Write ALL output in fluent, natural English.',
        };

        $platformAdvice = match ($platform) {
            'instagram' => 'Optimized for Instagram: captivating first 125 characters (before "more" truncation), clean line breaks, high visual aesthetic, and relevant hashtag cluster at the end.',
            'facebook' => 'Optimized for Facebook: strong narrative hook, clear formatting, conversational question to stimulate comments/shares, and direct call to action.',
            default => 'Versatile for both Facebook and Instagram: high-hook opening, engaging body, and strong call to action.',
        };

        $systemPrompt = <<<PROMPT
You are an elite Social Media Copywriter and Growth Strategist who writes viral, high-converting posts for Facebook and Instagram.
{$langPrompt}

Guidelines:
- {$platformAdvice}
- Tone style: {$tone}
- Primary objective: {$goal}
- Emojis allowed: {$includeEmojis}
- Include Call-to-Action: {$includeCta}
- Include Hashtags: {$includeHashtags}

You MUST respond with ONLY valid JSON with this exact schema:
{
  "variations": [
    {
      "type": "Hook & Story",
      "hook": "Attention grabbing opening line",
      "caption": "Full formatted caption with line breaks and emojis...",
      "first_comment": "5-10 strategic hashtags or clean hashtags to drop into first comment"
    },
    {
      "type": "Short & Punchy",
      "hook": "Bold one-line claim or question",
      "caption": "Concise high-impact caption...",
      "first_comment": "Strategic hashtags..."
    },
    {
      "type": "Value & Bullet Points",
      "hook": "Value proposition hook",
      "caption": "Bullet points formatted caption with CTA...",
      "first_comment": "Strategic hashtags..."
    }
  ],
  "hashtags": [
    "#Hashtag1",
    "#Hashtag2",
    "#Hashtag3",
    "#Hashtag4",
    "#Hashtag5",
    "#Hashtag6",
    "#Hashtag7",
    "#Hashtag8",
    "#Hashtag9",
    "#Hashtag10"
  ]
}
PROMPT;

        $userPrompt = "Generate 3 diverse social media post variations based on this topic/details:\n\n{$topic}";

        $result = $this->callAi($systemPrompt, $userPrompt, true);

        return [
            'variations' => $result['variations'] ?? [],
            'hashtags' => $result['hashtags'] ?? [],
        ];
    }

    /**
     * Rewrite, polish, or transform existing caption text
     */
    public function rewriteContent(string $text, string $action, array $options = []): array
    {
        $text = trim($text);
        if (empty($text)) {
            throw new Exception('Please provide the text you would like to improve.');
        }

        $instructions = match ($action) {
            'shorter', 'punchier' => 'Rewrite the caption to make it significantly shorter, punchier, and more concise. Cut out all fluff while keeping the core excitement.',
            'longer', 'expand' => 'Expand the caption with richer narrative depth, engaging context, and an open-ended question to spark community discussion.',
            'professional' => 'Rewrite the caption in an authoritative, polished, professional tone suitable for industry leaders and corporate audiences.',
            'engaging' => 'Rewrite the caption to make it highly engaging, friendly, warm, and conversational to maximize likes, shares, and comments.',
            'cta', 'stronger_cta' => 'Add or upgrade to a strong, high-converting Call to Action (CTA) at the end of the post inviting comments, shares, or clicks.',
            'fix_grammar' => 'Correct any typos, grammatical errors, and sentence flow while strictly preserving the author\'s original voice and message.',
            'rewrite' => 'Completely re-imagine and rewrite the caption with a fresh, captivating hook and modern social media angle.',
            'emojis' => 'Enhance the caption by weaving in expressive, tasteful emojis that match the message and structure.',
            'bullets' => 'Format the key ideas of the post into clean, scannable bullet points with matching emojis.',
            'translate' => 'Translate the caption into ' . ($options['target_language'] ?? 'English') . ' ensuring natural cultural idioms and engaging social media tone.',
            default => 'Improve the clarity, hook, and engagement potential of this social media caption.',
        };

        $systemPrompt = <<<PROMPT
You are a senior social media copy editor.
Your task: {$instructions}

CRITICAL RULES:
- Return ONLY valid JSON with this exact schema:
{
  "improved_text": "The improved, ready-to-publish social media caption",
  "explanation": "Brief 1-sentence note of what was improved"
}
PROMPT;

        $userPrompt = "Original Post Text:\n{$text}";

        $result = $this->callAi($systemPrompt, $userPrompt, true);

        return [
            'improved_text' => $result['improved_text'] ?? $text,
            'explanation' => $result['explanation'] ?? 'Caption refined for social media engagement.',
        ];
    }

    /**
     * Generate curated hashtag suggestions grouped by reach
     */
    public function suggestHashtags(string $content, ?string $niche = null, ?string $platform = null): array
    {
        $platformContext = $platform && $platform !== 'all' ? "Target Platform: " . ucfirst($platform) : "Target Platforms: Facebook & Instagram";
        $systemPrompt = <<<PROMPT
You are an Instagram and Facebook growth algorithm expert.
Analyze the provided post content and return a curated list of high-performing hashtags.
{$platformContext}
Return ONLY valid JSON with this exact schema:
{
  "broad_reach": ["#tag1", "#tag2", "#tag3", "#tag4", "#tag5"],
  "niche_specific": ["#tag6", "#tag7", "#tag8", "#tag9", "#tag10"],
  "community_tags": ["#tag11", "#tag12", "#tag13", "#tag14", "#tag15"],
  "all_hashtags_string": "#tag1 #tag2 #tag3 #tag4 #tag5 #tag6 #tag7 #tag8 #tag9 #tag10 #tag11 #tag12 #tag13 #tag14 #tag15"
}
PROMPT;

        $userPrompt = "Post Content:\n{$content}\n" . ($niche ? "Target Niche/Industry: {$niche}\n" : "");

        $result = $this->callAi($systemPrompt, $userPrompt, true);

        return [
            'broad_reach' => $result['broad_reach'] ?? [],
            'niche_specific' => $result['niche_specific'] ?? [],
            'community_tags' => $result['community_tags'] ?? [],
            'all_hashtags_string' => $result['all_hashtags_string'] ?? '',
        ];
    }

    /**
     * Suggest 3 smart, context-aware reply options for a social media comment in the Unified Inbox
     */
    public function suggestCommentReply(string $commentText, string $postCaption = '', string $tone = 'friendly'): array
    {
        $systemPrompt = <<<PROMPT
You are a professional Community & Social Media Manager.
Generate 3 distinct, authentic, and polite reply suggestions to a follower's comment on a business page.
Tone: {$tone}.

Reply Types:
1. Friendly & Appreciative (Quick, warm, and engaging)
2. Helpful & Informative (Professional, provides clarity or assistance)
3. Lead Conversion / DM Invitation (Politely directs follower to send a direct message or visit website)

Return ONLY valid JSON with this exact schema:
{
  "suggestions": [
    {
      "label": "Warm & Friendly",
      "reply": "Reply text here..."
    },
    {
      "label": "Professional & Helpful",
      "reply": "Reply text here..."
    },
    {
      "label": "Lead / Action-oriented",
      "reply": "Reply text here..."
    }
  ]
}
PROMPT;

        $userPrompt = "Post Caption Context:\n" . ($postCaption ?: 'Company business update') . "\n\nFollower Comment:\n\"{$commentText}\"";

        $result = $this->callAi($systemPrompt, $userPrompt, true);

        return [
            'suggestions' => $result['suggestions'] ?? [],
        ];
    }

    /**
     * Core AI API caller with JSON enforcement and error fallback
     */
    protected function callAi(string $systemPrompt, string $userPrompt, bool $isJson = true): array
    {
        $apiKey = $this->getApiKey();

        try {
            $payload = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.7,
            ];

            if ($isJson) {
                $payload['response_format'] = ['type' => 'json_object'];
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(35)->post($this->endpoint, $payload);

            if ($response->status() === 401) {
                throw new Exception('AI API key is invalid or unauthorized.');
            }

            if ($response->status() === 429) {
                throw new Exception('AI rate limit reached. Please wait a moment and try again.');
            }

            if (!$response->successful()) {
                Log::error('[SocialAiCopilot] API Error: ' . $response->body());
                throw new Exception("AI request failed (HTTP {$response->status()}): " . $response->body());
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? '{}';

            // Clean any potential markdown wrapping
            $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
            $content = preg_replace('/\s*```$/', '', $content);

            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                Log::warning('[SocialAiCopilot] Failed to parse JSON response: ' . $content);
                return ['raw' => $content];
            }

            return $parsed;

        } catch (Throwable $e) {
            Log::error('[SocialAiCopilot] Exception: ' . $e->getMessage());
            throw $e;
        }
    }
}

