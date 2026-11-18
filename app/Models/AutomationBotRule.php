<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AutomationBotRule extends Model { protected $fillable=['tenant_id','name','signal_type','pattern','classification','enabled']; protected $casts=['enabled'=>'boolean']; }
