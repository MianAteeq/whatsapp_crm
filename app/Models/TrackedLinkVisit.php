<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TrackedLinkVisit extends Model { protected $fillable=['tenant_id','tracked_link_id','contact_id','visitor_token','user_agent','ip_hash','classification','classification_reason','manual_override','raw_metadata','visited_at']; protected $casts=['manual_override'=>'boolean','raw_metadata'=>'array','visited_at'=>'datetime']; public function link(){return $this->belongsTo(TrackedLink::class,'tracked_link_id');} }
