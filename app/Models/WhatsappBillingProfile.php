<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappBillingProfile extends Model
{
    use HasFactory;

    // Supported Billing Statuses
    public const STATUS_NOT_CONFIGURED             = 'Not Configured';
    public const STATUS_CONFIGURATION_REQUIRED     = 'Configuration Required';
    public const STATUS_PENDING_VERIFICATION       = 'Pending Verification';
    public const STATUS_OPERATIONAL                = 'Operational';
    public const STATUS_PAYMENT_ATTENTION_REQUIRED = 'Payment Attention Required';
    public const STATUS_RESTRICTED                 = 'Restricted';
    public const STATUS_UNKNOWN                    = 'Unknown';

    protected $fillable = [
        'tenant_id',
        'whatsapp_account_id',
        'waba_id',
        'business_id',
        'billing_status',
        'currency',
        'country',
        'payment_method_status',
        'last_error_code',
        'last_error_message',
        'last_error_details',
        'last_checked_at',
        'last_successful_message_at',
    ];

    protected $casts = [
        'last_error_details'         => 'array',
        'last_checked_at'            => 'datetime',
        'last_successful_message_at' => 'datetime',
    ];

    /**
     * Local scope to enforce tenant boundary.
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Relationship to Tenant.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Relationship to WhatsApp Setting.
     */
    public function whatsappAccount()
    {
        return $this->belongsTo(WhatsappSetting::class, 'whatsapp_account_id');
    }

    /**
     * Helper to verify operational status.
     */
    public function isOperational(): bool
    {
        return $this->billing_status === self::STATUS_OPERATIONAL;
    }

    /**
     * Helper to check if configuration is required.
     */
    public function isConfigurationRequired(): bool
    {
        return $this->billing_status === self::STATUS_CONFIGURATION_REQUIRED;
    }
}

