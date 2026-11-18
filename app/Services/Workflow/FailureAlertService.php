<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\AutomationExecution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class FailureAlertService
{
    public function send(AutomationExecution $execution): void
    {
        if (!Schema::hasTable('automation_alert_settings')) return;
        $settings=DB::table('automation_alert_settings')->where('tenant_id',$execution->tenant_id)->where('enabled',true)->first(); if(!$settings)return;
        $fingerprint=hash('sha256',$execution->workflow_id.'|'.$execution->current_node_id.'|'.$execution->last_error);
        if(DB::table('automation_failure_alerts')->where('tenant_id',$execution->tenant_id)->where('fingerprint',$fingerprint)->where('created_at','>=',now()->subMinutes($settings->repeat_suppression_minutes))->exists())return;
        if(DB::table('automation_failure_alerts')->where('tenant_id',$execution->tenant_id)->whereNotNull('sent_at')->where('sent_at','>=',now()->subHour())->count()>=$settings->hourly_limit)return;
        $ids=array_map('intval',(array)json_decode($settings->recipient_user_ids?:'[]',true));
        $users=User::where('tenant_id',$execution->tenant_id)->where('status','active')->whereIn('id',$ids)->whereNotNull('email')->get();
        $alertId=DB::table('automation_failure_alerts')->insertGetId(['tenant_id'=>$execution->tenant_id,'execution_id'=>$execution->id,'fingerprint'=>$fingerprint,'status'=>'pending','recipient_user_ids'=>json_encode($users->pluck('id')->all()),'error_summary'=>Str::limit((string)$execution->last_error,2000,''),'created_at'=>now(),'updated_at'=>now()]);
        try { foreach($users as $user) Mail::raw("Workflow execution #{$execution->id} failed.\n\n{$execution->last_error}",fn($mail)=>$mail->to($user->email)->subject('Workflow execution failed')); DB::table('automation_failure_alerts')->where('id',$alertId)->update(['status'=>'sent','sent_at'=>now(),'updated_at'=>now()]); }
        catch(\Throwable $e){ DB::table('automation_failure_alerts')->where('id',$alertId)->update(['status'=>'failed','updated_at'=>now()]); report($e); }
    }
}
