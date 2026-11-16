<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class SalesPipeline extends Model { protected $fillable=['tenant_id','name','description','is_default','is_active']; protected $casts=['is_default'=>'boolean','is_active'=>'boolean']; public function stages(){return $this->hasMany(PipelineStage::class,'pipeline_id')->orderBy('position');} public function opportunities(){return $this->hasMany(Opportunity::class,'pipeline_id');} }
