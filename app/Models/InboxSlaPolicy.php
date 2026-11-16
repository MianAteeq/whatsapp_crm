<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InboxSlaPolicy extends Model { protected $fillable=['tenant_id','channel','response_minutes','warning_minutes','continuous_clock','human_reply_stops_clock','ai_reply_stops_clock','workflow_reply_stops_clock','is_active']; protected $casts=['continuous_clock'=>'boolean','human_reply_stops_clock'=>'boolean','ai_reply_stops_clock'=>'boolean','workflow_reply_stops_clock'=>'boolean','is_active'=>'boolean']; }

