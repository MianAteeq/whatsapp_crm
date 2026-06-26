<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SettingAuditLog;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;

class SystemSettingsController extends Controller
{
    /**
     * Get all system settings grouped by categories.
     */
    public function index()
    {
        $settings = Setting::all()->groupBy('group');
        return response()->json([
            'success' => true,
            'settings' => $settings
        ]);
    }

    /**
     * Update system settings.
     */
    public function update(Request $request)
    {
        // Dynamic validation rules based on keys present in request
        $rules = [];
        
        // Define common validation rules
        $commonRules = [
            'general.website_name' => 'nullable|string|max:255',
            'general.website_title' => 'nullable|string|max:255',
            'general.website_description' => 'nullable|string',
            'general.website_keywords' => 'nullable|string',
            'general.website_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'general.website_favicon' => 'nullable|image|mimes:ico,png,jpg|max:512',
            'general.footer_copyright' => 'nullable|string|max:255',
            'general.website_language' => 'nullable|string|max:10',
            'general.timezone' => 'nullable|string',
            'general.date_format' => 'nullable|string',
            'general.currency_code' => 'nullable|string|max:10',
            'general.currency_symbol' => 'nullable|string|max:10',
            'general.maintenance_mode' => 'nullable|string|in:true,false',
            
            'contact.company_name' => 'nullable|string|max:255',
            'contact.contact_email' => 'nullable|email',
            'contact.support_email' => 'nullable|email',
            'contact.phone_number' => 'nullable|string',
            'contact.whatsapp_number' => 'nullable|string',
            'contact.physical_address' => 'nullable|string',
            'contact.google_maps_embed' => 'nullable|string',

            'email.smtp_host' => 'nullable|string',
            'email.smtp_port' => 'nullable|numeric',
            'email.smtp_username' => 'nullable|string',
            'email.smtp_password' => 'nullable|string',
            'email.smtp_encryption' => 'nullable|string|in:tls,ssl,none',
            'email.sender_name' => 'nullable|string',
            'email.sender_email' => 'nullable|email',

            'auth.password_min_length' => 'nullable|numeric|min:6',
            'auth.session_timeout' => 'nullable|numeric|min:1',
            'media.max_upload_size' => 'nullable|numeric',
            'media.image_compression' => 'nullable|numeric|between:1,100',
        ];

        foreach ($request->all() as $key => $value) {
            $normalizedKey = $this->normalizeKey($key);
            if (isset($commonRules[$normalizedKey])) {
                $setting = Setting::where('key', $normalizedKey)->first();
                if ($setting && $setting->type === 'file' && !$request->hasFile($key)) {
                    $rules[$key] = 'nullable|string';
                } else {
                    $rules[$key] = $commonRules[$normalizedKey];
                }
            }
        }

        $validated = $request->validate($rules);

        $userId = auth()->id();
        $ip = $request->ip();
        $agent = $request->userAgent();

        foreach ($request->all() as $key => $value) {
            $normalizedKey = $this->normalizeKey($key);
            $setting = Setting::where('key', $normalizedKey)->first();
            if (!$setting) {
                continue;
            }

            if ($setting->type === 'file' && $request->hasFile($key)) {
                $file = $request->file($key);
                $fileName = 'setting_' . str_replace('.', '_', $normalizedKey) . '_' . time() . '.' . $file->getClientOriginalExtension();
                
                $dir = public_path('uploads/settings');
                if (!file_exists($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                $file->move($dir, $fileName);
                $value = asset('uploads/settings/' . $fileName);
            }

            SettingsService::set($normalizedKey, $value, $userId, $ip, $agent);
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings saved successfully'
        ]);
    }

    /**
     * Clear System Settings Cache.
     */
    public function clearCache()
    {
        SettingsService::clearCache();
        return response()->json([
            'success' => true,
            'message' => 'Settings cache tag flushed successfully'
        ]);
    }

    /**
     * Clear & Optimize Laravel Application Cache.
     */
    public function optimize()
    {
        try {
            Artisan::call('optimize:clear');
            return response()->json([
                'success' => true,
                'message' => 'Application cache cleared & performance sharding optimized successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Optimization script aborted: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Settings change history audit logs.
     */
    public function auditLogs()
    {
        $logs = SettingAuditLog::with('user')
            ->latest()
            ->paginate(30);

        return response()->json([
            'success' => true,
            'logs' => $logs
        ]);
    }

    /**
     * Send Test SMTP Email dynamically configuring Mailer client.
     */
    public function testEmail(Request $request)
    {
        $request->validate([
            'smtp_host' => 'required|string',
            'smtp_port' => 'required|numeric',
            'smtp_username' => 'nullable|string',
            'smtp_password' => 'nullable|string',
            'smtp_encryption' => 'nullable|string|in:tls,ssl,none',
            'sender_email' => 'required|email',
            'sender_name' => 'required|string',
            'test_recipient' => 'required|email'
        ]);

        try {
            Config::set('mail.mailers.smtp.host', $request->smtp_host);
            Config::set('mail.mailers.smtp.port', (int) $request->smtp_port);
            Config::set('mail.mailers.smtp.username', $request->smtp_username);
            Config::set('mail.mailers.smtp.password', $request->smtp_password);
            Config::set('mail.mailers.smtp.encryption', $request->smtp_encryption === 'none' ? null : $request->smtp_encryption);
            Config::set('mail.from.address', $request->sender_email);
            Config::set('mail.from.name', $request->sender_name);

            $recipient = $request->test_recipient;

            Mail::raw('This is a secure system alert verifying your SMTP connection credentials are functional.', function ($message) use ($recipient) {
                $message->to($recipient)
                    ->subject('THROB CRM: SMTP Settings Connection Handshake');
            });

            return response()->json([
                'success' => true,
                'message' => 'Test email dispatched to ' . $recipient . ' successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'SMTP Handshake Failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get public branding settings.
     */
    public function branding()
    {
        return response()->json([
            'success' => true,
            'website_name' => SettingsService::get('general.website_name', 'THROB CRM'),
            'website_title' => SettingsService::get('general.website_title', 'THROB - Premium WhatsApp CRM SaaS Portal'),
            'website_logo' => SettingsService::get('general.website_logo'),
            'website_favicon' => SettingsService::get('general.website_favicon'),
        ]);
    }

    /**
     * Get public pricing plans.
     */
    public function publicPlans()
    {
        $plans = \App\Models\Plan::all();
        return response()->json([
            'success' => true,
            'plans' => $plans
        ]);
    }

    /**
     * Normalize the request key by replacing only the first underscore with a dot.
     */
    private function normalizeKey(string $key): string
    {
        if (str_contains($key, '_')) {
            $parts = explode('_', $key, 2);
            return $parts[0] . '.' . $parts[1];
        }
        return $key;
    }
}
