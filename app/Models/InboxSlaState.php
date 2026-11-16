<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InboxSlaState extends Model { protected $fillable=['tenant_id','policy_id','channel','source_id','last_inbound_message_key','started_at','due_at','responded_at','status']; protected $casts=['started_at'=>'datetime','due_at'=>'datetime','responded_at'=>'datetime']; public function policy(){return $this->belongsTo(InboxSlaPolicy::class,'policy_id');} }

