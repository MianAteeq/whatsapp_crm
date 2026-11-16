<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class CustomField extends Model { protected $fillable=['tenant_id','entity_type','key','name','field_type','options','validation','is_required','is_active','position']; protected $casts=['options'=>'array','validation'=>'array','is_required'=>'boolean','is_active'=>'boolean']; }
