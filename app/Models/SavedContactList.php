<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class SavedContactList extends Model { protected $fillable=['tenant_id','user_id','name','description','filters','is_shared']; protected $casts=['filters'=>'array','is_shared'=>'boolean']; }
