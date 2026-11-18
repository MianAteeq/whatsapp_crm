<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TrackedLink extends Model { protected $fillable=['tenant_id','public_token','name','destination_url','repeat_click_policy','trigger_workflows']; protected $casts=['trigger_workflows'=>'boolean']; public function visits(){return $this->hasMany(TrackedLinkVisit::class);} }
