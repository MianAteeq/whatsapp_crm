<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappSetting extends Model
{
     protected $fillable = [
        'tenant_id',
        'access_token',
        'phone_number_id',
        'business_account_id',
        'phone_number',
        'business_name',
        'is_connected',
        'is_registered',
        'is_waba_subscribed',
        'waba_subscription_error',
        'messaging_limit_tier',
        'openai_key',
        'company_prompt',
    ];

    protected $casts = [
        'is_connected' => 'boolean',
        'is_registered' => 'boolean',
        'is_waba_subscribed' => 'boolean',
    ];
}
