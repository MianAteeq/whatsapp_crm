<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AuditLog extends Model { public $timestamps=false; protected $fillable=['tenant_id','user_id','actor_type','actor_label','module','action','auditable_type','auditable_id','description','old_values','new_values','metadata','ip_address','user_agent','created_at']; protected $casts=['old_values'=>'array','new_values'=>'array','metadata'=>'array','created_at'=>'datetime']; }
