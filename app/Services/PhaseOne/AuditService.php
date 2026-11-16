<?php
namespace App\Services\PhaseOne;
use App\Models\AuditLog;
class AuditService {
 public function record(int $tenantId,string $module,string $action,?object $subject=null,array $old=[],array $new=[],?string $description=null,array $metadata=[]): AuditLog {
  $request=request(); $user=$request->user();
  return AuditLog::create(['tenant_id'=>$tenantId,'user_id'=>$user?->id,'actor_type'=>$user?'user':'system','actor_label'=>$user?->name,'module'=>$module,'action'=>$action,'auditable_type'=>$subject?get_class($subject):null,'auditable_id'=>$subject?->id,'description'=>$description,'old_values'=>$old?:null,'new_values'=>$new?:null,'metadata'=>$metadata?:null,'ip_address'=>$request->ip(),'user_agent'=>substr((string)$request->userAgent(),0,1000),'created_at'=>now()]);
 }
}
