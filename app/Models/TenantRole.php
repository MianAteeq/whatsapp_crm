<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TenantRole extends Model { protected $fillable=['tenant_id','name','slug','description','permissions','is_system']; protected $casts=['permissions'=>'array','is_system'=>'boolean']; public function users(){ return $this->hasMany(User::class); } }
