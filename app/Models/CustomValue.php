<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class CustomValue extends Model { protected $fillable=['tenant_id','folder_id','key','name','value','value_type','is_active','usage_count']; protected $casts=['is_active'=>'boolean','usage_count'=>'integer']; public function folder(){return $this->belongsTo(CustomValueFolder::class,'folder_id');} }
