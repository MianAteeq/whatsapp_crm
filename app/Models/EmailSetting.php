<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSetting extends Model
{
    protected $table = 'email_settings';

    protected $fillable = [
        'tenant_id',
        'provider',
        'from_name',
        'from_email',
        'reply_to',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'api_key',
        'deepseek_api_key',
        'mailtrap_api_token',
        'mailtrap_webhook_secret',
        'mailtrap_domain',
        'mailtrap_inbox_id',
        'api_domain',
        'rate_limit_per_minute',
        'daily_limit',
        'is_active',
        'extra_config',
    ];

    protected $casts = [
        'smtp_port' => 'integer',
        'rate_limit_per_minute' => 'integer',
        'daily_limit' => 'integer',
        'is_active' => 'boolean',
        'extra_config' => 'array',
        'smtp_password' => 'encrypted',
        'api_key' => 'encrypted',
        'deepseek_api_key' => 'encrypted',
        'mailtrap_api_token' => 'encrypted',
        'mailtrap_webhook_secret' => 'encrypted',
    ];

    protected $hidden = [
        'smtp_password',
        'api_key',
        'deepseek_api_key',
        'mailtrap_api_token',
        'mailtrap_webhook_secret',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get safe array for API output (masking secrets)
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'provider' => $this->provider,
            'from_name' => $this->from_name,
            'from_email' => $this->from_email,
            'reply_to' => $this->reply_to,
            'smtp_host' => $this->smtp_host,
            'smtp_port' => $this->smtp_port,
            'smtp_encryption' => $this->smtp_encryption,
            'smtp_username' => $this->smtp_username,
            'has_smtp_password' => !empty($this->smtp_password),
            'has_api_key' => !empty($this->api_key),
            'has_deepseek_api_key' => !empty($this->deepseek_api_key) || !empty(env('DEEPSEEK_API_KEY')) || !empty(config('services.deepseek.api_key')),
            'has_mailtrap_api_token' => !empty($this->mailtrap_api_token) || !empty(env('MAILTRAP_API_TOKEN')),
            'has_mailtrap_webhook_secret' => !empty($this->mailtrap_webhook_secret) || !empty(env('MAILTRAP_WEBHOOK_SECRET')),
            'mailtrap_domain' => $this->mailtrap_domain ?: env('MAILTRAP_DOMAIN', ''),
            'mailtrap_inbox_id' => $this->mailtrap_inbox_id,
            'api_domain' => $this->api_domain,
            'rate_limit_per_minute' => $this->rate_limit_per_minute,
            'daily_limit' => $this->daily_limit,
            'is_active' => (bool) $this->is_active,
            'extra_config' => $this->extra_config,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
