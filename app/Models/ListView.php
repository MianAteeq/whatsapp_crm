<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class ListView extends Model { protected $fillable=['tenant_id','user_id','entity_type','name','columns','sorts','filters','is_default']; protected $casts=['columns'=>'array','sorts'=>'array','filters'=>'array','is_default'=>'boolean']; }
