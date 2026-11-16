<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InboxSlaEvent extends Model { public $timestamps=false; protected $fillable=['tenant_id','user_id','channel','source_id','event_type','metadata','created_at']; protected $casts=['metadata'=>'array','created_at'=>'datetime']; }

