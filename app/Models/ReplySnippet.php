<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ReplySnippet extends Model { protected $fillable=['tenant_id','created_by_user_id','title','category','body','visibility','is_active']; protected $casts=['is_active'=>'boolean']; public function creator(){return $this->belongsTo(User::class,'created_by_user_id');} }

