<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailSetting;
use App\Services\Email\EmailProviderFactory;
use App\Services\Email\Providers\SmtpEmailProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
                    'provider' => 'smtp',
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
            'provider' => 'required|string|in:mailtrap,smtp,sendgrid,log',
            'from_name' => 'required|string|max:191',
            'from_email' => 'required|email|max:191',
            'reply_to' => 'nullable|email|max:191',
            'smtp_host' => 'nullable|string|max:191',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|string|in:tls,ssl,none',
            'smtp_username' => 'nullable|string|max:191',
            'smtp_password' => 'nullable|string',
            'api_key' => 'nullable|string',
            'deepseek_api_key' => 'nullable|string',
            'mailtrap_api_token' => 'nullable|string',
            'mailtrap_webhook_secret' => 'nullable|string',
            'mailtrap_domain' => 'nullable|string|max:191',
            'mailtrap_inbox_id' => 'nullable|string|max:191',
            'rate_limit_per_minute' => 'nullable|integer|min:1|max:1000',
            'daily_limit' => 'nullable|integer|min:10|max:50000',
            'is_active' => 'nullable|boolean',
        ]);

        $setting = EmailSetting::firstOrNew(['tenant_id' => $tenantId]);
        
        $setting->provider = $validated['provider'];
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

        // Only update mailtrap_api_token if provided and not masked placeholder
        if (!empty($validated['mailtrap_api_token']) && !str_contains($validated['mailtrap_api_token'], '•••')) {
            $setting->mailtrap_api_token = $validated['mailtrap_api_token'];
        }

        // Only update mailtrap_webhook_secret if provided and not masked placeholder
        if (!empty($validated['mailtrap_webhook_secret']) && !str_contains($validated['mailtrap_webhook_secret'], '•••')) {
            $setting->mailtrap_webhook_secret = $validated['mailtrap_webhook_secret'];
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
     * Test connection with given credentials
     */
    public function testConnection(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $testTo = $request->input('test_email', $request->user()->email);
        $providerType = $request->input('provider', 'mailtrap');

        if ($providerType === 'mailtrap') {
            $apiToken = $request->input('mailtrap_api_token');
            if (empty($apiToken) || str_contains($apiToken, '•••')) {
                $saved = EmailSetting::where('tenant_id', $tenantId)->first();
                $apiToken = $saved?->mailtrap_api_token ?: env('MAILTRAP_API_TOKEN');
            }

            if (empty($apiToken)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Mailtrap API Token is required to test Mailtrap connection.',
                ], 422);
            }

            $provider = new \App\Services\Email\Providers\MailtrapEmailProvider(
                apiToken: $apiToken,
                sendingDomain: $request->input('mailtrap_domain')
            );

            $fromEmail = $request->input('from_email') ?: env('MAILTRAP_FROM_EMAIL', 'test@domain.com');
            $fromName = $request->input('from_name') ?: env('MAILTRAP_FROM_NAME', 'Mailtrap Test');

            $result = $provider->send([
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'to_email' => $testTo,
                'subject' => 'Mailtrap API Connection Test',
                'html_body' => '<p>Your Mailtrap Email API configuration was tested successfully!</p>',
                'text_body' => 'Your Mailtrap Email API configuration was tested successfully!',
            ]);

            if ($result['success']) {
                $msgId = $result['message_id'] ?? 'N/A';
                return response()->json([
                    'success' => true,
                    'message' => "Mailtrap connection successful! Mailtrap accepted the test email. Message ID: {$msgId}",
                    'message_id' => $msgId,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Mailtrap connection test failed.',
                ], 400);
            }
        }

        if ($providerType === 'smtp') {
            $host = $request->input('smtp_host');
            $port = (int)($request->input('smtp_port') ?: 587);
            $encryption = $request->input('smtp_encryption', 'tls');
            $username = $request->input('smtp_username');
            $password = $request->input('smtp_password');

            // If password omitted or masked, read from saved setting
            if (empty($password) || str_contains($password, '•••')) {
                $saved = EmailSetting::where('tenant_id', $tenantId)->first();
                $password = $saved?->smtp_password;
            }

            if (empty($host)) {
                return response()->json([
                    'success' => false,
                    'error' => 'SMTP Host is required.',
                ], 422);
            }

            $provider = new SmtpEmailProvider(
                host: $host,
                port: $port,
                encryption: $encryption,
                username: $username,
                password: $password,
                timeout: 8
            );

            $result = $provider->send([
                'from_email' => $request->input('from_email', 'test@example.com'),
                'from_name' => $request->input('from_name', 'Test System'),
                'to_email' => $testTo,
                'subject' => 'SMTP Connection Test',
                'html_body' => '<p>Your SMTP configuration was tested successfully!</p>',
            ]);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => "Connection successful! Test email accepted and sent to {$testTo}.",
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Connection test failed.',
                ], 400);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Provider test passed.',
        ]);
    }
}
