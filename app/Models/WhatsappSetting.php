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

        'is_connected'

    ];
}
