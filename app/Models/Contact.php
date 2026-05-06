<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = [

        'tenant_id',

        'name',
        'phone',
        'email',

        'company',
        'job_title',

        'address',
        'birthday',

        'website',

        'notes',

        'status'
    ];
    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }
}
