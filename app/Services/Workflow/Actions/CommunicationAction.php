<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Models\ContactCommunicationPreference;
use App\Models\EmailSetting;
use App\Models\User;
use App\Services\Workflow\WorkflowContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class CommunicationAction implements ActionHandlerInterface
{
    public function execute(WorkflowContext $context, array $config): ActionResponse
    {
        $channel=$config['operation']??'';$contact=$context->getContact();$tenantId=(int)$context->getWorkflow()->tenant_id;
        if($channel==='internal_notification') return $this->notify($tenantId,$config);
        if(!$contact) return ActionResponse::failed('Contact lookup failed.');
        $preference=ContactCommunicationPreference::where('tenant_id',$tenantId)->where('contact_id',$contact->id)->first();
        $allowed=match($channel){'send_email'=>$preference?->email_allowed??true,'send_sms'=>$preference?->sms_allowed??true,'call_contact'=>$preference?->phone_allowed??true,default=>false};
        if(!$allowed) return ActionResponse::failed('Customer communication preference blocks this channel.');
        if($channel!=='send_email') return ActionResponse::failed('The requested channel provider is not configured for this account.');
        if(!$contact->email) return ActionResponse::failed('Contact has no email address.');
        $settings=EmailSetting::where('tenant_id',$tenantId)->where('is_active',true)->first();
        if(!$settings || !$settings->from_email) return ActionResponse::failed('No active validated email sender is configured.');
        Mail::raw((string)($config['body']??''),function($message)use($contact,$settings,$config){$message->to($contact->email)->subject((string)($config['subject']??'Message'))->from($settings->from_email,$settings->from_name);if($settings->reply_to)$message->replyTo($settings->reply_to);});
        return ActionResponse::success('Email accepted by the configured provider.',['channel'=>'email','provider'=>$settings->provider,'delivery_status'=>'accepted']);
    }

    private function notify(int $tenantId,array $config): ActionResponse
    {
        $recipient=User::where('tenant_id',$tenantId)->where('status','active')->find($config['recipient_user_id']??0);
        if(!$recipient)return ActionResponse::failed('Authorized internal recipient lookup failed.');
        Log::channel(config('logging.default'))->info('Workflow internal notification',['tenant_id'=>$tenantId,'recipient_user_id'=>$recipient->id,'message'=>$config['body']??'']);
        return ActionResponse::success('Internal notification recorded for delivery.',['recipient_user_id'=>$recipient->id]);
    }
}
