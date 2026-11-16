<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ContactCommunicationPreference extends Model { protected $fillable=['tenant_id','contact_id','email_allowed','whatsapp_allowed','sms_allowed','phone_allowed','source','reason','email_unsubscribed_at','whatsapp_opted_out_at']; protected $casts=['email_allowed'=>'boolean','whatsapp_allowed'=>'boolean','sms_allowed'=>'boolean','phone_allowed'=>'boolean','email_unsubscribed_at'=>'datetime','whatsapp_opted_out_at'=>'datetime']; }
