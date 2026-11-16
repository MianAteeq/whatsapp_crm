<?php
namespace App\Services\PhaseOne;
use App\Models\{Contact,DuplicateReviewItem,TenantContactPolicy};
use App\Services\Scraper\PhoneNormalizationService;
class ContactMatchingService {
 public function inspect(int $tenantId,?string $email,?string $phone,?int $excludeId=null,string $source='manual'): array {
  $policy=TenantContactPolicy::firstOrCreate(['tenant_id'=>$tenantId]); if($policy->allow_duplicates)return ['matches'=>collect(),'policy'=>$policy];
  $email=$email?strtolower(trim($email)):null;$phone=$phone?app(PhoneNormalizationService::class)->normalizeE164($phone):null;
  $q=Contact::where('tenant_id',$tenantId)->when($excludeId,fn($x)=>$x->where('id','!=',$excludeId))->where(function($x)use($policy,$email,$phone){$used=false;if($policy->match_email&&$email){$x->where('email_normalized',$email);$used=true;}if($policy->match_phone&&$phone){$used?$x->orWhere('phone_normalized',$phone):$x->where('phone_normalized',$phone);}});
  $matches=(!$email&&!$phone)?collect():$q->get(); return compact('matches','policy');
 }
 public function guard(int $tenantId,array $data,?int $excludeId=null,string $source='manual'): ?DuplicateReviewItem {
  $result=$this->inspect($tenantId,$data['email']??null,$data['phone']??null,$excludeId,$source);if($result['matches']->isEmpty())return null;
  return DuplicateReviewItem::create(['tenant_id'=>$tenantId,'source'=>$source,'incoming_data'=>$data,'candidate_contact_ids'=>$result['matches']->pluck('id')->all(),'reasons'=>['Matched an existing normalized email address or phone number.'],'status'=>$result['policy']->ambiguous_action==='review'?'pending':'rejected']);
 }
}
