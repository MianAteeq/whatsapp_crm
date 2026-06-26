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
        'messaging_limit_tier',
        'openai_key',
        'company_prompt',
    ];

}
