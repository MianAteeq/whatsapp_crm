<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['user_id', 'action', 'key', 'old_value', 'new_value', 'ip_address', 'user_agent'])]
class SettingAuditLog extends Model
{
    protected $table = 'settings_audit_logs';

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
