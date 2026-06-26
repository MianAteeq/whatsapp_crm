<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'limits' => 'array',
        ];
    }
}
