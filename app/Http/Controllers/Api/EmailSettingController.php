<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EmailSettingController extends Controller
{
    /**
     * Get the tenant's email provider settings
     */
    public function show(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $setting = EmailSetting::where('tenant_id', $tenantId)->first();

        if (!$setting) {
            return response()->json([
                'success' => true,
                'data' => [
                    'provider' => 'mailtrap',
                    'from_name' => $request->user()->name ?? 'Marketing',
                    'from_email' => $request->user()->email ?? '',
                    'reply_to' => '',
                    'smtp_host' => '',
                    'smtp_port' => 587,
                    'smtp_encryption' => 'tls',
                    'smtp_username' => '',
                    'has_smtp_password' => false,
                    'has_api_key' => false,
                    'has_deepseek_api_key' => !empty(env('DEEPSEEK_API_KEY')) || !empty(config('services.deepseek.api_key')),
                    'rate_limit_per_minute' => 60,
                    'daily_limit' => 5000,
                    'is_active' => true,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $setting->toSafeArray(),
        ]);
    }

    /**
     * Create or update email provider settings
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;

        $validated = $request->validate([
            'provider' => 'sometimes|string|in:mailtrap',
            'from_name' => 'required|string|max:191',
            'from_email' => 'required|email|max:191',
            'reply_to' => 'nullable|email|max:191',
            'smtp_host' => 'prohibited',
            'smtp_port' => 'prohibited',
            'smtp_encryption' => 'prohibited',
            'smtp_username' => 'prohibited',
            'smtp_password' => 'prohibited',
            'api_key' => 'prohibited',
            'deepseek_api_key' => 'nullable|string',
            'mailtrap_api_token' => 'prohibited',
            'mailtrap_webhook_secret' => 'prohibited',
            'mailtrap_domain' => 'nullable|string|max:191',
            'mailtrap_inbox_id' => 'nullable|string|max:191',
            'rate_limit_per_minute' => 'nullable|integer|min:1|max:1000',
            'daily_limit' => 'nullable|integer|min:10|max:50000',
            'is_active' => 'nullable|boolean',
        ]);

        $setting = EmailSetting::firstOrNew(['tenant_id' => $tenantId]);
        
        $setting->provider = 'mailtrap';
        $setting->from_name = $validated['from_name'];
        $setting->from_email = $validated['from_email'];
        $setting->reply_to = $validated['reply_to'] ?? null;
        $setting->smtp_host = $validated['smtp_host'] ?? null;
        $setting->smtp_port = $validated['smtp_port'] ?? 587;
        $setting->smtp_encryption = $validated['smtp_encryption'] ?? 'tls';
        $setting->smtp_username = $validated['smtp_username'] ?? null;
        $setting->mailtrap_domain = $validated['mailtrap_domain'] ?? null;
        $setting->mailtrap_inbox_id = $validated['mailtrap_inbox_id'] ?? null;

        // Only update password if provided and not masked placeholder
        if (!empty($validated['smtp_password']) && !str_contains($validated['smtp_password'], '•••')) {
            $setting->smtp_password = $validated['smtp_password'];
        }

        // Only update api_key if provided and not masked placeholder
        if (!empty($validated['api_key']) && !str_contains($validated['api_key'], '•••')) {
            $setting->api_key = $validated['api_key'];
        }

        // Only update deepseek_api_key if provided and not masked placeholder
        if (!empty($validated['deepseek_api_key']) && !str_contains($validated['deepseek_api_key'], '•••')) {
            $setting->deepseek_api_key = $validated['deepseek_api_key'];
        }

        $setting->rate_limit_per_minute = $validated['rate_limit_per_minute'] ?? 60;
        $setting->daily_limit = $validated['daily_limit'] ?? 5000;
        $setting->is_active = $validated['is_active'] ?? true;
        $setting->save();

        return response()->json([
            'success' => true,
            'message' => 'Email settings saved successfully.',
            'data' => $setting->toSafeArray(),
        ]);
    }

    /**
     * Test delivery through the platform account and a verified customer domain.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $request->validate([
            'provider' => 'sometimes|string|in:mailtrap',
            'test_email' => 'nullable|email',
            'from_email' => 'nullable|email',
            'from_name' => 'nullable|string|max:191',
        ]);

        try {
            $result = \App\Services\Email\EmailCampaignService::sendTestEmail(
                (int) $request->user()->tenant_id,
                [
                    'test_email' => $request->input('test_email') ?: $request->user()->email,
                    'from_email' => $request->input('from_email'),
                    'from_name' => $request->input('from_name'),
                    'subject' => 'Email Connection Test',
                    'html_body' => '<p>Your email sending configuration was tested successfully!</p>',
                    'text_body' => 'Your email sending configuration was tested successfully!',
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'The platform accepted the test email for delivery.',
                'message_id' => $result['message_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
