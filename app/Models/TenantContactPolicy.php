<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TenantContactPolicy extends Model { protected $fillable=['tenant_id','allow_duplicates','match_email','match_phone','ambiguous_action','email_verification_policy','unsubscribe_footer_enabled','unsubscribe_label']; protected $casts=['allow_duplicates'=>'boolean','match_email'=>'boolean','match_phone'=>'boolean','unsubscribe_footer_enabled'=>'boolean']; }
