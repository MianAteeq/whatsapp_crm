<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ContactCommunicationPreference;
use App\Models\EmailSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ProcessEmailSequenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries=3;
    public function __construct(public int $enrollmentId) {}
    public function handle(): void
    {
        $enrollment=DB::table('email_sequence_enrollments')->where('id',$this->enrollmentId)->where('status','active')->first();if(!$enrollment)return;
        $sequence=DB::table('email_sequences')->where('id',$enrollment->sequence_id)->where('tenant_id',$enrollment->tenant_id)->where('status','active')->first();if(!$sequence)return;
        $contact=Contact::where('tenant_id',$enrollment->tenant_id)->find($enrollment->contact_id);$preference=ContactCommunicationPreference::where('tenant_id',$enrollment->tenant_id)->where('contact_id',$enrollment->contact_id)->first();
        if(!$contact?->email||($preference&&!$preference->email_allowed)){DB::table('email_sequence_enrollments')->where('id',$enrollment->id)->update(['status'=>'stopped','stop_reason'=>'Email preference or unsubscribe blocks delivery.','updated_at'=>now()]);return;}
        $step=DB::table('email_sequence_steps')->where('sequence_id',$sequence->id)->where('position',$enrollment->current_step+1)->first();if(!$step){DB::table('email_sequence_enrollments')->where('id',$enrollment->id)->update(['status'=>'completed','updated_at'=>now()]);return;}
        $settings=EmailSetting::where('tenant_id',$enrollment->tenant_id)->where('is_active',true)->first();if(!$settings||!$settings->from_email){$this->log($enrollment,$step,'failed',null,'No active email provider.');return;}
        try{Mail::raw((string)$step->body,function($mail)use($contact,$settings,$step){$mail->to($contact->email)->subject($step->subject)->from($settings->from_email,$settings->from_name);});$this->log($enrollment,$step,'accepted',$settings->provider,null);$next=DB::table('email_sequence_steps')->where('sequence_id',$sequence->id)->where('position',$step->position+1)->first();if(!$next){DB::table('email_sequence_enrollments')->where('id',$enrollment->id)->update(['status'=>'completed','current_step'=>$step->position,'updated_at'=>now()]);return;}$at=now()->addMinutes($next->delay_minutes);DB::table('email_sequence_enrollments')->where('id',$enrollment->id)->update(['current_step'=>$step->position,'next_send_at'=>$at,'updated_at'=>now()]);self::dispatch($enrollment->id)->delay($at);}catch(\Throwable $e){$this->log($enrollment,$step,'failed',$settings->provider,$e->getMessage());throw $e;}
    }
    private function log(object $enrollment,object $step,string $status,?string $provider,?string $error):void{DB::table('email_sequence_logs')->insert(['tenant_id'=>$enrollment->tenant_id,'enrollment_id'=>$enrollment->id,'step_id'=>$step->id,'status'=>$status,'provider'=>$provider,'error'=>$error,'sent_at'=>$status==='accepted'?now():null,'created_at'=>now(),'updated_at'=>now()]);}
}
