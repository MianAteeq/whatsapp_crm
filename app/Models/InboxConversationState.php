<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InboxConversationState extends Model { protected $fillable=['tenant_id','channel','source_id','assigned_to_user_id','status','priority']; public function assignee(){return $this->belongsTo(User::class,'assigned_to_user_id');} }

