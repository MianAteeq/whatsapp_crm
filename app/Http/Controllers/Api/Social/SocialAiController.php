<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Services\Social\SocialAiCopilotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class SocialAiController extends Controller
{
    public function __construct(
        protected SocialAiCopilotService $copilot
    ) {}

    /**
     * Generate 3 post caption variations and hashtags from a topic
     */
    public function generateCaptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:1500'],
            'platform' => ['nullable', 'string', 'in:all,facebook,instagram'],
            'tone' => ['nullable', 'string', 'max:50'],
            'goal' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'in:en,ar,ur,es,fr,de,pt,hi,it'],
            'include_emojis' => ['nullable', 'boolean'],
            'include_hashtags' => ['nullable', 'boolean'],
            'include_cta' => ['nullable', 'boolean'],
        ]);

        try {
            $data = $this->copilot->generateCaptions($validated);

            return response()->json([
                'success' => true,
                'message' => 'Captions generated successfully',
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Rewrite or polish existing caption text
     */
    public function rewrite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
            'action' => ['required', 'string', 'in:shorter,punchier,longer,expand,professional,engaging,cta,stronger_cta,fix_grammar,rewrite,emojis,bullets,translate'],
            'target_language' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $data = $this->copilot->rewriteContent(
                $validated['text'],
                $validated['action'],
                ['target_language' => $validated['target_language'] ?? 'Arabic']
            );

            return response()->json([
                'success' => true,
                'message' => 'Text updated successfully',
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Generate hashtag suggestions for a post
     */
    public function suggestHashtags(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
            'niche' => ['nullable', 'string', 'max:100'],
            'platform' => ['nullable', 'string', 'in:all,facebook,instagram'],
        ]);

        try {
            $data = $this->copilot->suggestHashtags(
                $validated['content'],
                $validated['niche'] ?? null,
                $validated['platform'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Generate smart reply suggestions for an inbox comment
     */
    public function suggestReply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:1000'],
            'post_caption' => ['nullable', 'string', 'max:1500'],
            'tone' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $data = $this->copilot->suggestCommentReply(
                $validated['comment'],
                $validated['post_caption'] ?? '',
                $validated['tone'] ?? 'friendly'
            );

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}

