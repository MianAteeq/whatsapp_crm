<?php
namespace App\Services\PhaseOne;
use App\Models\{Contact,TenantContactPolicy};
class RecipientEmailVerificationService {
 public function allowed(int $tenantId,?Contact $contact,string $email): array {
  $policy=TenantContactPolicy::firstOrCreate(['tenant_id'=>$tenantId]); if($policy->email_verification_policy==='disabled')return [true,null];
  if($contact && $contact->email_verification_status==='valid')return [true,null];
  $valid=(bool)filter_var($email,FILTER_VALIDATE_EMAIL); if($contact)$contact->update(['email_verification_status'=>$valid?'valid':'invalid','email_verification_checked_at'=>now()]);
  return [$valid,$valid?null:'Recipient email failed first-send validation.'];
 }
}
