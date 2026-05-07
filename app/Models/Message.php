<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{

protected $guarded = [];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
