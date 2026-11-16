<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class TagCategory extends Model { protected $fillable=['tenant_id','name','description','color']; public function tags(){return $this->hasMany(Tag::class);} }
