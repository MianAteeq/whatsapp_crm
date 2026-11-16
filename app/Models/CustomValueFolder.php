<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class CustomValueFolder extends Model { protected $fillable=['tenant_id','name','description']; public function values(){return $this->hasMany(CustomValue::class,'folder_id');} }
