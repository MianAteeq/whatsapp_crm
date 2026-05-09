<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappTemplate extends Model
{
    protected $casts = [

        'components' => 'array'

    ];

    protected $fillable = [

        'tenant_id',

        'template_id',

        'name',

        'category',

        'language',

        'status',

        'components'

    ];
}
