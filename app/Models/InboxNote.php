<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InboxNote extends Model { protected $fillable=['tenant_id','user_id','channel','source_id','content']; public function user(){return $this->belongsTo(User::class);} }

