<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class CustomFieldValue extends Model { protected $fillable=['tenant_id','custom_field_id','entity_type','entity_id','value']; protected $casts=['value'=>'array']; }
