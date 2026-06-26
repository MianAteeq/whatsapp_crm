<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SettingAuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class SettingsService
{
    protected static $cachePrefix = 'system_setting.';

    /**
     * Get a setting by key. Caches for performance.
     */
    public static function get(string $key, $default = null)
    {
        return Cache::rememberForever(self::$cachePrefix . $key, function () use ($key, $default) {
            $setting = Setting::where('key', $key)->first();
            if (!$setting) {
                return $default;
            }
            return self::castValue($setting->value, $setting->type);
        });
    }

    /**
     * Set a setting by key, updates cache and writes audit log.
     */
    public static function set(string $key, $value, ?int $userId = null, ?string $ip = null, ?string $agent = null): bool
    {
        $setting = Setting::where('key', $key)->first();
        if (!$setting) {
            return false;
        }

        $oldValue = $setting->value;
        $newValue = is_null($value) ? null : (string) $value;

        // Skip updating if value hasn't changed
        if ($oldValue === $newValue) {
            return true;
        }

        $setting->value = $newValue;
        $setting->save();

        // Clear the cache key
        Cache::forget(self::$cachePrefix . $key);
        Cache::forget(self::$cachePrefix . 'all_grouped');

        // Audit Log entry
        try {
            SettingAuditLog::create([
                'user_id' => $userId ?? Auth::id() ?? 1, // fallback to superadmin or current user
                'action' => 'updated',
                'key' => $key,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'ip_address' => $ip,
                'user_agent' => $agent
            ]);
        } catch (\Exception $e) {
            logger()->error('Settings Audit logging failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Flush all cached settings.
     */
    public static function clearCache(): void
    {
        $keys = Setting::pluck('key')->toArray();
        foreach ($keys as $key) {
            Cache::forget(self::$cachePrefix . $key);
        }
        Cache::forget(self::$cachePrefix . 'all_grouped');
    }

    /**
     * Cast string value to native types.
     */
    private static function castValue($value, string $type)
    {
        if (is_null($value)) {
            return null;
        }

        switch ($type) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'integer':
                return (int) $value;
            case 'file':
                return $value; // returns URL path string
            default:
                return $value;
        }
    }
}
