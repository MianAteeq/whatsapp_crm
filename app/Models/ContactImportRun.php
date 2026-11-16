<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class ContactImportRun extends Model { protected $fillable=['tenant_id','user_id','file_name','mapping','status','total_rows','accepted_rows','rejected_rows','errors','completed_at']; protected $casts=['mapping'=>'array','errors'=>'array','completed_at'=>'datetime']; public function user(){return $this->belongsTo(User::class);} }
