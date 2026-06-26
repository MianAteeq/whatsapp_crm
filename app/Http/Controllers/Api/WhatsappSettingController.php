<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSetting;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

class WhatsappSettingController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'access_token'       => 'required|string',
            'phone_number_id'    => 'required|string',
            'business_account_id'=> 'required|string',
            'phone_number'       => 'nullable|string',
            'business_name'      => 'nullable|string',
        ]);

        $existing = WhatsappSetting::where('tenant_id', auth()->user()->tenant_id)->first();
        $accessToken = $request->access_token;
        if ($existing && (str_contains($accessToken, '•') || $accessToken === 'CONFIGURED')) {
            $accessToken = $existing->access_token;
        }

        $isRegistered = false;
        if ($existing && $existing->phone_number_id === $request->phone_number_id) {
            $isRegistered = (bool)$existing->is_registered;
        }

        $setting = WhatsappSetting::updateOrCreate(
            ['tenant_id' => auth()->user()->tenant_id],
            [
                'access_token'        => $accessToken,
                'phone_number_id'     => $request->phone_number_id,
                'business_account_id' => $request->business_account_id,
                'phone_number'        => $request->phone_number,
                'business_name'       => $request->business_name,
                'is_connected'        => true,
                'is_registered'       => $isRegistered,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp API connected successfully',
            'data'    => $this->formatSetting($setting, true),
        ]);
    }

    public function show(Request $request)
    {
        $setting = WhatsappSetting::where(
            'tenant_id',
            auth()->user()->tenant_id
        )->first();

        return response()->json([
            'success' => true,
            'data'    => $setting ? $this->formatSetting($setting, $request->boolean('sync')) : null,
        ]);
    }

    /**
     * Disconnect — mark as disconnected (soft) instead of deleting
     * so credentials are preserved for reconnect.
     *
     * DELETE /whatsapp/settings
     */
    public function destroy()
    {
        WhatsappSetting::where('tenant_id', auth()->user()->tenant_id)
            ->update(['is_connected' => false]);

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp disconnected successfully.',
        ]);
    }

    /**

     * Receives the short-lived code + waba_id + phone_number_id,
     * exchanges the code for a long-lived system user access token via Meta Graph API,
     * and stores the credentials.
     *
     * POST /whatsapp/connect
     */
    public function connect(Request $request)
    {
        $request->validate([
            'code'            => 'required|string',
            'waba_id'         => 'required|string',
            'phone_number_id' => 'required|string',
        ]);

        try {
            // Exchange the short-lived OAuth code for a long-lived access token
            $appId     = config('services.facebook.app_id',     env('FACEBOOK_APP_ID'));
            $appSecret = config('services.facebook.app_secret', env('FACEBOOK_APP_SECRET'));

            if (!$appId || !$appSecret) {
                return response()->json([
                    'success' => false,
                    'message' => 'FACEBOOK_APP_ID and FACEBOOK_APP_SECRET are not configured in the server .env. '
                               . 'Please add them, or use the Settings page to enter your System User Access Token manually.',
                ], 422);
            }

            $tokenResponse = \Illuminate\Support\Facades\Http::get('https://graph.facebook.com/v18.0/oauth/access_token', [
                'client_id'     => $appId,
                'client_secret' => $appSecret,
                'code'          => $request->code,
            ]);

            if (!$tokenResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to exchange OAuth code for access token: ' . $tokenResponse->body(),
                ], 422);
            }

            $accessToken = $tokenResponse->json('access_token');

            $existing = WhatsappSetting::where('tenant_id', auth()->user()->tenant_id)->first();
            $isRegistered = false;
            if ($existing && $existing->phone_number_id === $request->phone_number_id) {
                $isRegistered = (bool)$existing->is_registered;
            }

            $setting = WhatsappSetting::updateOrCreate(
                ['tenant_id' => auth()->user()->tenant_id],
                [
                    'access_token'        => $accessToken,
                    'phone_number_id'     => $request->phone_number_id,
                    'business_account_id' => $request->waba_id,
                    'is_connected'        => true,
                    'is_registered'       => $isRegistered,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'WhatsApp Business Account connected successfully',
                'data'    => $this->formatSetting($setting, true),
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Register the verified phone number with Meta Cloud API.
     *
     * POST /whatsapp/register
     */
    public function registerNumber(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|size:6',
        ]);

        $setting = WhatsappSetting::where('tenant_id', auth()->user()->tenant_id)->first();

        if (!$setting || !$setting->access_token || !$setting->phone_number_id) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp API settings not found. Please connect your account first.',
            ], 422);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($setting->access_token)
                ->post("https://graph.facebook.com/v18.0/{$setting->phone_number_id}/register", [
                    'messaging_product' => 'whatsapp',
                    'pin'               => $request->pin,
                ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Registration failed: ' . ($response->json('error.message') ?? $response->body()),
                ], 422);
            }

            $setting->update(['is_registered' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Phone number registered successfully with Meta!',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Map DB model to a consistent response shape the frontend expects.
     */
    private function formatSetting(WhatsappSetting $setting, bool $forceSync = false): array
    {
        $shouldSync = $forceSync || (
            $setting->is_connected &&
            $setting->access_token &&
            (
                empty($setting->phone_number) ||
                empty($setting->business_name) ||
                empty($setting->messaging_limit_tier) ||
                $setting->updated_at->lt(now()->subMinutes(5))
            )
        );

        if ($shouldSync) {
            try {
                $response = \Illuminate\Support\Facades\Http::withToken($setting->access_token)
                    ->get("https://graph.facebook.com/v18.0/{$setting->phone_number_id}", [
                        'fields' => 'display_phone_number,verified_name,messaging_limit_tier,whatsapp_business_manager_messaging_limit'
                    ]);
                
                if ($response->successful()) {
                    $metaLimit = $response->json('whatsapp_business_manager_messaging_limit');
                    $metaTier = $response->json('messaging_limit_tier');

                    $tier = $setting->messaging_limit_tier;
                    if ($metaLimit !== null) {
                        $limitStr = strtoupper(trim((string)$metaLimit));
                        if ($limitStr === '250') {
                            $tier = 'TIER_250';
                        } elseif ($limitStr === '1000' || $limitStr === '1000.0' || $limitStr === '1K') {
                            $tier = 'TIER_1K';
                        } elseif ($limitStr === '2000' || $limitStr === '2000.0' || $limitStr === '2K') {
                            $tier = 'TIER_2K';
                        } elseif ($limitStr === '10000' || $limitStr === '10000.0' || $limitStr === '10K') {
                            $tier = 'TIER_10K';
                        } elseif ($limitStr === '100000' || $limitStr === '100000.0' || $limitStr === '100K') {
                            $tier = 'TIER_100K';
                        } elseif ($limitStr === 'UNLIMITED') {
                            $tier = 'TIER_UNLIMITED';
                        } else {
                            $tier = str_starts_with($limitStr, 'TIER_') ? $limitStr : 'TIER_' . $limitStr;
                        }
                    } elseif ($metaTier !== null) {
                        $tier = $metaTier;
                    }

                    $setting->update([
                        'phone_number'  => $response->json('display_phone_number') ?? $setting->phone_number,
                        'business_name' => $response->json('verified_name') ?? $setting->business_name,
                        'messaging_limit_tier' => $tier ?? $setting->messaging_limit_tier,
                    ]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[WhatsApp Auto-Fetch] Failed to fetch phone number info: ' . $e->getMessage());
            }
        }

        return [
            'id'                   => $setting->id,
            'access_token'         => $setting->access_token ? str_repeat('•', 24) : null,
            'phone_number_id'      => $setting->phone_number_id,
            'business_account_id'  => $setting->business_account_id,
            'phone_number'         => $setting->phone_number  ?? null,
            'business_name'        => $setting->business_name ?? null,
            'status'               => $setting->is_connected ? 'connected' : 'disconnected',
            'is_registered'        => (bool)($setting->is_registered ?? false),
            'webhook_status'       => 'active',
            'last_synced'          => $setting->updated_at?->toISOString(),
            'messaging_limit_tier' => $setting->messaging_limit_tier ?? 'TIER_250',
            'openai_key'           => $setting->openai_key ? str_repeat('•', 24) : null,
            'company_prompt'       => $setting->company_prompt,
        ];
    }

    /**
     * Create or update WhatsApp AI configurations (OpenAI key, company prompt).
     */
    public function updateAiSettings(Request $request)
    {
        $request->validate([
            'openai_key'     => 'nullable|string',
            'company_prompt' => 'nullable|string',
        ]);

        $existing = WhatsappSetting::where('tenant_id', auth()->user()->tenant_id)->first();
        
        $openaiKey = $request->openai_key;
        if ($existing && $openaiKey && (str_contains($openaiKey, '•') || $openaiKey === 'CONFIGURED')) {
            $openaiKey = $existing->openai_key;
        }

        $setting = WhatsappSetting::updateOrCreate(
            ['tenant_id' => auth()->user()->tenant_id],
            [
                'openai_key'     => $openaiKey,
                'company_prompt' => $request->company_prompt,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'AI settings updated successfully',
            'data'    => $this->formatSetting($setting, true),
        ]);
    }

    /**
     * Retrieve WhatsApp business profile (logo, description, etc.) from Meta.
     */
    public function getProfile()
    {
        $setting = WhatsappSetting::where('tenant_id', auth()->user()->tenant_id)->first();

        if (!$setting || !$setting->access_token || !$setting->phone_number_id) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp settings not found. Please connect your account first.',
            ], 422);
        }

        // Auto-register in background if not registered
        if (!$setting->is_registered) {
            try {
                $regResponse = \Illuminate\Support\Facades\Http::withToken($setting->access_token)
                    ->post("https://graph.facebook.com/v18.0/{$setting->phone_number_id}/register", [
                        'messaging_product' => 'whatsapp',
                        'pin'               => '123456',
                    ]);

                if ($regResponse->successful()) {
                    $setting->update(['is_registered' => true]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[WhatsApp Auto-Register] Failed during profile fetch: ' . $e->getMessage());
            }
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($setting->access_token)
                ->get("https://graph.facebook.com/v18.0/{$setting->phone_number_id}/whatsapp_business_profile", [
                    'fields' => 'description,about,profile_picture_url',
                ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch WhatsApp profile: ' . ($response->json('error.message') ?? $response->body()),
                ], $response->status());
            }

            $profileData = $response->json('data.0') ?? [];

            return response()->json([
                'success' => true,
                'data' => [
                    'description' => $profileData['description'] ?? '',
                    'about' => $profileData['about'] ?? '',
                    'profile_picture_url' => $profileData['profile_picture_url'] ?? '',
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch WhatsApp profile: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update WhatsApp business profile (description and about status).
     */
    public function updateProfile(Request $request)
    {
        $request->validate([
            'description' => 'nullable|string|max:512',
            'about'       => 'nullable|string|max:139',
        ]);

        $setting = WhatsappSetting::where('tenant_id', auth()->user()->tenant_id)->first();

        if (!$setting || !$setting->access_token || !$setting->phone_number_id) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp settings not found. Please connect your account first.',
            ], 422);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($setting->access_token)
                ->post("https://graph.facebook.com/v18.0/{$setting->phone_number_id}/whatsapp_business_profile", [
                    'messaging_product' => 'whatsapp',
                    'description'       => $request->description ?? '',
                    'about'             => $request->about ?? '',
                ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update WhatsApp profile: ' . ($response->json('error.message') ?? $response->body()),
                ], $response->status());
            }

            return response()->json([
                'success' => true,
                'message' => 'WhatsApp profile updated successfully!',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update WhatsApp profile: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload and set the WhatsApp business logo (profile picture).
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg|max:5120', // max 5MB
        ]);

        $setting = WhatsappSetting::where('tenant_id', auth()->user()->tenant_id)->first();

        if (!$setting || !$setting->access_token || !$setting->phone_number_id) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp settings not found. Please connect your account first.',
            ], 422);
        }

        $appId = config('services.facebook.app_id', env('FACEBOOK_APP_ID'));

        if (!$appId) {
            return response()->json([
                'success' => false,
                'message' => 'FACEBOOK_APP_ID is not configured in the server environment.',
            ], 500);
        }

        try {
            $file = $request->file('logo');
            $fileName = $file->getClientOriginalName();
            $fileSize = $file->getSize();
            $fileType = $file->getMimeType();

            // Step 1: Initiate Resumable Upload Session
            $initResponse = \Illuminate\Support\Facades\Http::withToken($setting->access_token)
                ->post("https://graph.facebook.com/v18.0/{$appId}/uploads", [
                    'file_name'   => $fileName,
                    'file_length' => $fileSize,
                    'file_type'   => $fileType,
                ]);

            if (!$initResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to initiate logo upload session: ' . ($initResponse->json('error.message') ?? $initResponse->body()),
                ], $initResponse->status());
            }

            $sessionId = $initResponse->json('id');

            // Step 2: Upload Binary File Data
            $uploadResponse = \Illuminate\Support\Facades\Http::withToken($setting->access_token)
                ->withHeaders([
                    'file_offset' => 0,
                ])
                ->withBody(file_get_contents($file->getRealPath()), $fileType)
                ->post("https://graph.facebook.com/v18.0/{$sessionId}");

            if (!$uploadResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to upload logo binary: ' . ($uploadResponse->json('error.message') ?? $uploadResponse->body()),
                ], $uploadResponse->status());
            }

            $profilePictureHandle = $uploadResponse->json('h');

            // Step 3: Link Logo Handle to WhatsApp Business Profile
            $linkResponse = \Illuminate\Support\Facades\Http::withToken($setting->access_token)
                ->post("https://graph.facebook.com/v18.0/{$setting->phone_number_id}/whatsapp_business_profile", [
                    'messaging_product'      => 'whatsapp',
                    'profile_picture_handle' => $profilePictureHandle,
                ]);

            if (!$linkResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to set profile logo: ' . ($linkResponse->json('error.message') ?? $linkResponse->body()),
                ], $linkResponse->status());
            }

            // Retrieve updated profile to get the new image URL
            $profileResponse = \Illuminate\Support\Facades\Http::withToken($setting->access_token)
                ->get("https://graph.facebook.com/v18.0/{$setting->phone_number_id}/whatsapp_business_profile", [
                    'fields' => 'profile_picture_url',
                ]);

            $newLogoUrl = $profileResponse->successful() ? ($profileResponse->json('data.0.profile_picture_url') ?? '') : '';

            return response()->json([
                'success'             => true,
                'message'             => 'Logo uploaded and updated successfully!',
                'profile_picture_url' => $newLogoUrl,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload logo: ' . $e->getMessage(),
            ], 500);
        }
    }
    public function testConnection(
        Request $request,
        WhatsAppService $whatsAppService
    ) {

        $request->validate([

            'phone' => 'required'

        ]);


        $response = $whatsAppService->sendText(

            auth()->user()->tenant_id,

            $request->phone,

            'WhatsApp API connected successfully.'

        );


        return response()->json([

            'success' => true,

            'response' => $response

        ]);
    }

    /**
     * Retrieve WhatsApp dashboard stats from local database.
     */
    public function dashboardStats()
    {
        $tenantId = auth()->user()->tenant_id;

        $totalConversations = \App\Models\Conversation::where('tenant_id', $tenantId)->count();

        $messagesSentToday = \App\Models\Message::where('tenant_id', $tenantId)
            ->where('direction', 'outgoing')
            ->whereDate('created_at', \Carbon\Carbon::today())
            ->count();

        $messagesReceivedToday = \App\Models\Message::where('tenant_id', $tenantId)
            ->where('direction', 'incoming')
            ->whereDate('created_at', \Carbon\Carbon::today())
            ->count();

        $activeChats = \App\Models\Conversation::where('tenant_id', $tenantId)
            ->where('updated_at', '>=', \Carbon\Carbon::now()->subDay())
            ->count();

        $setting = WhatsappSetting::where('tenant_id', $tenantId)->first();
        $messagingLimitTier = $setting ? ($setting->messaging_limit_tier ?? 'TIER_250') : 'TIER_250';

        return response()->json([
            'total_conversations'      => $totalConversations,
            'messages_sent_today'      => $messagesSentToday,
            'messages_received_today'  => $messagesReceivedToday,
            'active_chats'             => $activeChats,
            'messaging_limit_tier'     => $messagingLimitTier,
        ]);
    }
}
