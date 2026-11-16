<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InboxUserState extends Model { protected $fillable=['tenant_id','user_id','channel','source_id','is_starred','is_following','unread_override','last_viewed_at']; protected $casts=['is_starred'=>'boolean','is_following'=>'boolean','unread_override'=>'boolean','last_viewed_at'=>'datetime']; }

