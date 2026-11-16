<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DuplicateReviewItem extends Model { protected $fillable=['tenant_id','source','incoming_data','candidate_contact_ids','reasons','status','resolved_by','resolved_contact_id']; protected $casts=['incoming_data'=>'array','candidate_contact_ids'=>'array','reasons'=>'array']; }
