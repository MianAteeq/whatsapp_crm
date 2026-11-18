<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutomationBotRule;
use App\Models\AutomationWorkflow;
use App\Models\AutomationNode;
use App\Models\AutomationConnection;
use App\Models\ContactCommunicationPreference;
use App\Models\TrackedLink;
use App\Models\TrackedLinkVisit;
use App\Services\Workflow\BotClassifier;
use App\Services\Workflow\WorkflowTriggerManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PhaseFourAutomationController extends Controller
{
    public function folders(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        if ($request->isMethod('post')) {
            $data = $request->validate(['name'=>'required|string|max:255','parent_id'=>'nullable|integer']);
            return response()->json(DB::table('automation_folders')->insertGetId(array_merge($data,['tenant_id'=>$tenantId,'created_at'=>now(),'updated_at'=>now()])), 201);
        }
        return response()->json(DB::table('automation_folders')->where('tenant_id',$tenantId)->orderBy('name')->get()->map(function($folder) use($tenantId){$folder->workflow_count=AutomationWorkflow::where('tenant_id',$tenantId)->where('folder_id',$folder->id)->count();return $folder;}));
    }

    public function comments(Request $request, int $workflowId): JsonResponse
    {
        $tenantId=auth()->user()->tenant_id;
        abort_unless(AutomationWorkflow::where('tenant_id',$tenantId)->whereKey($workflowId)->exists(),404);
        if($request->isMethod('post')){$data=$request->validate(['body'=>'required|string|max:5000','node_id'=>'nullable|string|max:255']);$id=DB::table('automation_comments')->insertGetId(array_merge($data,['tenant_id'=>$tenantId,'workflow_id'=>$workflowId,'user_id'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()]));return response()->json(['id'=>$id],201);}
        return response()->json(DB::table('automation_comments')->where('tenant_id',$tenantId)->where('workflow_id',$workflowId)->latest('id')->get());
    }

    public function templates(): JsonResponse
    {
        $catalog = [
            [
                'key' => 'welcome_onboarding',
                'name' => 'Welcome and Onboarding',
                'category' => 'Welcome and Onboarding',
                'description' => 'Instantly welcome new WhatsApp contacts with an interactive menu and assign account tags.',
                'preview' => [
                    'trigger' => 'Contact Created / Inbound Opt-in',
                    'steps' => ['Receive Inbound WhatsApp Opt-in', 'Send WhatsApp Welcome Message', 'Wait 5 Minutes', 'Tag Contact as "Onboarding Complete"']
                ],
                'required_mappings' => ['contact_field_mapping', 'whatsapp_template_name'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'lead_qualification',
                'name' => 'Lead Qualification',
                'category' => 'Lead Qualification',
                'description' => 'Qualify inbound prospects with interactive buttons and route qualified leads to sales.',
                'preview' => [
                    'trigger' => 'Incoming WhatsApp Message with Keyword',
                    'steps' => ['Keyword Match "Interested"', 'Send Qualification Template', 'Wait for Response', 'Update Opportunity Stage to "Qualified"']
                ],
                'required_mappings' => ['contact_field_mapping', 'stage_mapping'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'lead_nurturing',
                'name' => 'Lead Nurturing',
                'category' => 'Lead Nurturing',
                'description' => 'Multi-touch WhatsApp sequence that educates leads over 3 days.',
                'preview' => [
                    'trigger' => 'Lead Form Submitted',
                    'steps' => ['Form Submitted', 'Send WhatsApp Brochure', 'Wait 24 Hours', 'Send Case Study Follow-up', 'Notify Sales Team']
                ],
                'required_mappings' => ['contact_field_mapping', 'stage_mapping'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'appointment_confirmation',
                'name' => 'Appointment Confirmation',
                'category' => 'Appointment Confirmation',
                'description' => 'Dispatch instant WhatsApp appointment confirmation with location and calendar details.',
                'preview' => [
                    'trigger' => 'Appointment Created',
                    'steps' => ['Booking Confirmed', 'Send WhatsApp Confirmation Template', 'Tag Contact "Booked"']
                ],
                'required_mappings' => ['appointment_field_mapping'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'appointment_reminder',
                'name' => 'Appointment Reminder',
                'category' => 'Appointment Reminder',
                'description' => 'Send automated WhatsApp reminder 2 hours prior to scheduled appointment.',
                'preview' => [
                    'trigger' => 'Appointment-Relative Wait (2 Hours Before)',
                    'steps' => ['Time Reached', 'Send WhatsApp Reminder with Confirmation Buttons', 'Record Confirmation']
                ],
                'required_mappings' => ['appointment_field_mapping'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'missed_appointment_follow_up',
                'name' => 'Missed Appointment Follow-Up',
                'category' => 'Missed Appointment Follow-Up',
                'description' => 'Re-engage no-shows with a friendly WhatsApp reschedule link.',
                'preview' => [
                    'trigger' => 'Appointment Updated to "No Show"',
                    'steps' => ['No Show Status Detected', 'Send Reschedule Offer Template', 'Tag Contact "Reschedule Pending"']
                ],
                'required_mappings' => ['appointment_field_mapping'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'abandoned_checkout',
                'name' => 'Abandoned Checkout',
                'category' => 'Abandoned Checkout',
                'description' => 'Recover abandoned carts with tracked link and exclusive WhatsApp discount.',
                'preview' => [
                    'trigger' => 'Incoming Webhook "cart.abandoned"',
                    'steps' => ['Cart Abandoned', 'Wait 30 Minutes', 'Send WhatsApp Recovery Template with Cart Link', 'Notify Rep if Over $500']
                ],
                'required_mappings' => ['contact_field_mapping', 'cart_field_mapping'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'payment_reminder',
                'name' => 'Payment Reminder',
                'category' => 'Payment Reminder',
                'description' => 'Send courteous invoice reminders on WhatsApp with direct payment link.',
                'preview' => [
                    'trigger' => 'Invoice Due Date Approaching',
                    'steps' => ['Due in 24 Hours', 'Send WhatsApp Payment Reminder', 'Wait for Payment Webhook']
                ],
                'required_mappings' => ['invoice_field_mapping'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'review_request',
                'name' => 'Review Request',
                'category' => 'Review Request',
                'description' => 'Request Google / Trustpilot reviews 24 hours after service completion.',
                'preview' => [
                    'trigger' => 'Opportunity Won / Service Completed',
                    'steps' => ['Service Done', 'Wait 24 Hours', 'Send Review Request Template', 'Tag "Review Requested"']
                ],
                'required_mappings' => ['contact_field_mapping'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'customer_reactivation',
                'name' => 'Customer Reactivation',
                'category' => 'Customer Reactivation',
                'description' => 'Re-engage inactive customers who haven\'t messaged in 45 days.',
                'preview' => [
                    'trigger' => 'Stale Opportunity / 45 Days Inactive',
                    'steps' => ['Inactivity Triggered', 'Send "We Miss You" WhatsApp Template', 'Route Reply to Priority Queue']
                ],
                'required_mappings' => ['contact_field_mapping'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'faq_chatbot',
                'name' => 'FAQ Chatbot',
                'category' => 'FAQ Chatbot',
                'description' => 'Answer common pricing, hours, and location questions via WhatsApp automatically.',
                'preview' => [
                    'trigger' => 'Keyword Received "Hours" / "Price" / "Location"',
                    'steps' => ['Keyword Match', 'Branch by Inquiry Type', 'Send Automated Answer', 'Offer Live Agent Handoff']
                ],
                'required_mappings' => ['faq_keywords_mapping'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'out_of_office',
                'name' => 'Out-of-Office Response',
                'category' => 'Out-of-Office Response',
                'description' => 'Send automatic notice when customers message outside of business hours.',
                'preview' => [
                    'trigger' => 'Incoming Message Outside Business Hours',
                    'steps' => ['Evaluate Business Hours Condition', 'Outside Hours: Send Automated Notice', 'Tag "Pending Morning Follow-up"']
                ],
                'required_mappings' => ['business_hours_mapping'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'human_agent_handoff',
                'name' => 'Human-Agent Handoff',
                'category' => 'Human-Agent Handoff',
                'description' => 'Smooth transition from bot to live agent when user requests human support.',
                'preview' => [
                    'trigger' => 'Keyword "Agent" / "Human" or Button Click',
                    'steps' => ['Handoff Triggered', 'Assign Contact to Support Agent', 'Send "Connecting you to an agent" Notice', 'Notify Team']
                ],
                'required_mappings' => ['agent_assignment_mapping'],
                'supports_isolated_test' => true,
            ],
            [
                'key' => 'staff_assignment',
                'name' => 'Staff Assignment',
                'category' => 'Staff Assignment',
                'description' => 'Round-robin or territory assignment of high-value WhatsApp leads to reps.',
                'preview' => [
                    'trigger' => 'Click-to-WhatsApp Lead Received',
                    'steps' => ['Lead Received', 'Assign Responsible Staff Member', 'Send Internal WhatsApp Notification', 'Send Welcome Message']
                ],
                'required_mappings' => ['staff_list_mapping'],
                'supports_isolated_test' => true,
            ],
        ];

        return response()->json($catalog);
    }

    public function createFromTemplate(Request $request, string $key): JsonResponse
    {
        $definitions = [
            'welcome_onboarding' => [
                'trigger' => ['type' => 'incoming_message', 'label' => 'Incoming WhatsApp Message'],
                'action' => ['type' => 'send_message', 'label' => 'Send Welcome Message', 'message' => 'Hello {{contact.name}}! Welcome to our service. How can we help you today?'],
                'tag' => ['type' => 'add_tag', 'label' => 'Add "Welcome Onboard" Tag', 'tagName' => 'Welcome Onboard']
            ],
            'lead_qualification' => [
                'trigger' => ['type' => 'keyword', 'label' => 'Keyword "Interested"', 'keywords' => 'interested, demo, pricing'],
                'action' => ['type' => 'send_message', 'label' => 'Send Qualification Questions', 'message' => 'Thanks for your interest! What service or package are you looking for?'],
                'tag' => ['type' => 'update_contact_fields', 'label' => 'Mark Lead as Qualified', 'status' => 'qualified']
            ],
            'lead_nurturing' => [
                'trigger' => ['type' => 'lead_form_submitted', 'label' => 'Lead Form Submitted'],
                'action' => ['type' => 'send_message', 'label' => 'Send WhatsApp Guide', 'message' => 'Hi {{contact.name}}, here is the resource guide you requested.'],
                'tag' => ['type' => 'wait', 'label' => 'Wait 24 Hours', 'amount' => 24, 'unit' => 'hours']
            ],
            'appointment_confirmation' => [
                'trigger' => ['type' => 'appointment_created', 'label' => 'Appointment Booked'],
                'action' => ['type' => 'send_message', 'label' => 'Send Confirmation Notice', 'message' => 'Your appointment is confirmed! We look forward to meeting with you.'],
                'tag' => ['type' => 'add_tag', 'label' => 'Tag "Booked"', 'tagName' => 'Appointment Confirmed']
            ],
            'appointment_reminder' => [
                'trigger' => ['type' => 'appointment_relative_wait', 'label' => '2 Hours Prior to Appointment'],
                'action' => ['type' => 'send_message', 'label' => 'Send WhatsApp Reminder', 'message' => 'Reminder: Your appointment is in 2 hours. Reply YES to confirm.'],
                'tag' => ['type' => 'wait_for_response', 'label' => 'Wait for Confirmation Reply']
            ],
            'missed_appointment_follow_up' => [
                'trigger' => ['type' => 'appointment_updated', 'label' => 'Status changed to "No Show"'],
                'action' => ['type' => 'send_message', 'label' => 'Send Re-booking Link', 'message' => 'We missed you today! You can easily reschedule anytime here.'],
                'tag' => ['type' => 'add_tag', 'label' => 'Tag "Needs Reschedule"', 'tagName' => 'Needs Reschedule']
            ],
            'abandoned_checkout' => [
                'trigger' => ['type' => 'incoming_webhook', 'label' => 'Cart Abandoned Webhook'],
                'action' => ['type' => 'send_message', 'label' => 'Send Cart Recovery WhatsApp', 'message' => 'Hi {{contact.name}}, you left items in your cart! Complete your order today.'],
                'tag' => ['type' => 'add_tag', 'label' => 'Tag "Cart Abandoned"', 'tagName' => 'Cart Abandoned']
            ],
            'payment_reminder' => [
                'trigger' => ['type' => 'schedule', 'label' => 'Invoice Due Date Approaching'],
                'action' => ['type' => 'send_message', 'label' => 'Send Payment Link', 'message' => 'Friendly reminder: Your invoice is due. Click here to complete payment.'],
                'tag' => ['type' => 'add_tag', 'label' => 'Tag "Payment Pending"', 'tagName' => 'Payment Pending']
            ],
            'review_request' => [
                'trigger' => ['type' => 'opportunity_stage_changed', 'label' => 'Opportunity Won / Completed'],
                'action' => ['type' => 'send_message', 'label' => 'Send Review Request', 'message' => 'Thank you for choosing us! Would you take 30 seconds to leave us a quick review?'],
                'tag' => ['type' => 'add_tag', 'label' => 'Tag "Review Sent"', 'tagName' => 'Review Sent']
            ],
            'customer_reactivation' => [
                'trigger' => ['type' => 'stale_opportunity', 'label' => '45 Days Inactivity'],
                'action' => ['type' => 'send_message', 'label' => 'Send Special Offer', 'message' => 'We miss you! Here is an exclusive offer for your next booking.'],
                'tag' => ['type' => 'add_tag', 'label' => 'Tag "Reactivated Lead"', 'tagName' => 'Reactivated Lead']
            ],
            'faq_chatbot' => [
                'trigger' => ['type' => 'keyword', 'label' => 'FAQ Keyword "Pricing/Hours"', 'keywords' => 'hours, pricing, location'],
                'action' => ['type' => 'send_message', 'label' => 'Send Automated FAQ Reply', 'message' => 'Our business hours are Mon-Fri 9am-6pm. Reply HUMAN to chat with our staff.'],
                'tag' => ['type' => 'add_tag', 'label' => 'Tag "FAQ User"', 'tagName' => 'FAQ User']
            ],
            'out_of_office' => [
                'trigger' => ['type' => 'incoming_message', 'label' => 'Incoming Message Outside Hours'],
                'action' => ['type' => 'send_message', 'label' => 'Send Out-of-Office Notice', 'message' => 'We are currently offline. We will reply as soon as we open at 9:00 AM!'],
                'tag' => ['type' => 'add_tag', 'label' => 'Tag "Pending Morning Reply"', 'tagName' => 'Pending Morning Reply']
            ],
            'human_agent_handoff' => [
                'trigger' => ['type' => 'keyword', 'label' => 'Keyword "Agent" / "Human"', 'keywords' => 'agent, human, support, help'],
                'action' => ['type' => 'send_message', 'label' => 'Connecting to Agent Notice', 'message' => 'Connecting you to our customer support team now. Please stay on WhatsApp.'],
                'tag' => ['type' => 'assign_agent', 'label' => 'Assign Support Agent', 'agentName' => 'Next Available Agent']
            ],
            'staff_assignment' => [
                'trigger' => ['type' => 'contact_created', 'label' => 'New WhatsApp Contact Received'],
                'action' => ['type' => 'assign_agent', 'label' => 'Assign Staff Member', 'agentName' => 'Account Executive'],
                'tag' => ['type' => 'internal_notification', 'label' => 'Notify Staff on WhatsApp']
            ],
        ];

        abort_unless(isset($definitions[$key]), 404, 'Template not recognized.');
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'field_mapping' => 'nullable|array',
            'stage_mapping' => 'nullable|array'
        ]);

        $def = $definitions[$key];
        $tenantId = auth()->user()->tenant_id;

        $workflow = DB::transaction(function () use ($data, $key, $def, $tenantId) {
            $workflow = AutomationWorkflow::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'description' => 'Draft created from ' . Str::headline($key) . ' playbook.',
                'status' => 'inactive',
                'version' => '1.0.0',
                'lock_version' => 0,
                'draft_saved_at' => now(),
                'created_by' => auth()->id()
            ]);

            $triggerId = (string) Str::uuid();
            $actionId = (string) Str::uuid();
            $tagId = (string) Str::uuid();
            $endId = (string) Str::uuid();

            // 1. Trigger node
            AutomationNode::create([
                'id' => $triggerId,
                'workflow_id' => $workflow->id,
                'type' => $def['trigger']['type'],
                'label' => $def['trigger']['label'],
                'config' => $def['trigger'],
                'position_x' => 320,
                'position_y' => 60
            ]);

            // 2. Action node
            AutomationNode::create([
                'id' => $actionId,
                'workflow_id' => $workflow->id,
                'type' => $def['action']['type'],
                'label' => $def['action']['label'],
                'config' => $def['action'],
                'position_x' => 320,
                'position_y' => 220
            ]);

            // 3. Followup / Tag / CRM node
            AutomationNode::create([
                'id' => $tagId,
                'workflow_id' => $workflow->id,
                'type' => $def['tag']['type'],
                'label' => $def['tag']['label'],
                'config' => $def['tag'],
                'position_x' => 320,
                'position_y' => 380
            ]);

            // 4. End node
            AutomationNode::create([
                'id' => $endId,
                'workflow_id' => $workflow->id,
                'type' => 'end_workflow',
                'label' => 'End Workflow',
                'config' => [],
                'position_x' => 320,
                'position_y' => 540
            ]);

            // Wire connections
            AutomationConnection::create([
                'workflow_id' => $workflow->id,
                'source_node_id' => $triggerId,
                'target_node_id' => $actionId,
                'source_handle' => 'bottom',
                'target_handle' => 'top'
            ]);

            AutomationConnection::create([
                'workflow_id' => $workflow->id,
                'source_node_id' => $actionId,
                'target_node_id' => $tagId,
                'source_handle' => 'bottom',
                'target_handle' => 'top'
            ]);

            AutomationConnection::create([
                'workflow_id' => $workflow->id,
                'source_node_id' => $tagId,
                'target_node_id' => $endId,
                'source_handle' => 'bottom',
                'target_handle' => 'top'
            ]);

            return $workflow;
        });

        return response()->json([
            'created' => true,
            'workflow_id' => $workflow->id,
            'status' => 'draft',
            'builder_url' => '/automation/workflows/builder/' . $workflow->id
        ], 201);
    }

    public function sequences(Request $request): JsonResponse
    {
        $tenantId=auth()->user()->tenant_id;
        if($request->isMethod('post')){$data=$request->validate(['name'=>'required|string|max:255','folder_id'=>'nullable|integer','stop_on_response'=>'nullable|boolean']);$id=DB::table('email_sequences')->insertGetId(array_merge($data,['tenant_id'=>$tenantId,'status'=>'draft','created_at'=>now(),'updated_at'=>now()]));return response()->json(['id'=>$id,'status'=>'draft'],201);}
        return response()->json(DB::table('email_sequences')->where('tenant_id',$tenantId)->orderByDesc('updated_at')->paginate(25));
    }

    public function saveSequence(Request $request, int $id): JsonResponse
    {
        $tenantId=auth()->user()->tenant_id;$sequence=DB::table('email_sequences')->where('tenant_id',$tenantId)->where('id',$id)->first();abort_unless($sequence,404);
        $data=$request->validate(['lock_version'=>'required|integer','name'=>'nullable|string|max:255','status'=>'nullable|in:draft,active,paused,completed','stop_on_response'=>'nullable|boolean','steps'=>'present|array|max:100','steps.*.delay_minutes'=>'required|integer|min:0','steps.*.subject'=>'required|string|max:255','steps.*.body'=>'nullable|string']);
        if((int)$sequence->lock_version!==(int)$data['lock_version']) return response()->json(['message'=>'Sequence changed elsewhere.'],409);
        DB::transaction(function()use($id,$data,$sequence){DB::table('email_sequences')->where('id',$id)->update(['name'=>$data['name']??$sequence->name,'status'=>$data['status']??$sequence->status,'stop_on_response'=>$data['stop_on_response']??$sequence->stop_on_response,'lock_version'=>$sequence->lock_version+1,'draft_saved_at'=>now(),'updated_at'=>now()]);DB::table('email_sequence_steps')->where('sequence_id',$id)->delete();foreach($data['steps'] as $position=>$step)DB::table('email_sequence_steps')->insert(array_merge($step,['sequence_id'=>$id,'position'=>$position+1,'created_at'=>now(),'updated_at'=>now()]));});
        return response()->json(['saved'=>true,'lock_version'=>$sequence->lock_version+1,'saved_at'=>now()->toIso8601String()]);
    }

    public function enrollSequence(Request $request,int $id):JsonResponse
    {
        $tenantId=auth()->user()->tenant_id;$sequence=DB::table('email_sequences')->where('tenant_id',$tenantId)->where('status','active')->where('id',$id)->first();abort_unless($sequence,404);$data=$request->validate(['contact_id'=>'required|integer']);$contact=\App\Models\Contact::where('tenant_id',$tenantId)->find($data['contact_id']);abort_unless($contact,404);$preference=ContactCommunicationPreference::where('tenant_id',$tenantId)->where('contact_id',$contact->id)->first();if($preference&&!$preference->email_allowed)return response()->json(['message'=>'Contact has unsubscribed from email.'],422);$first=DB::table('email_sequence_steps')->where('sequence_id',$id)->orderBy('position')->first();if(!$first)return response()->json(['message'=>'Sequence has no steps.'],422);$enrollmentId=DB::table('email_sequence_enrollments')->insertGetId(['tenant_id'=>$tenantId,'sequence_id'=>$id,'contact_id'=>$contact->id,'status'=>'active','current_step'=>0,'next_send_at'=>now()->addMinutes($first->delay_minutes),'created_at'=>now(),'updated_at'=>now()]);\App\Jobs\ProcessEmailSequenceJob::dispatch($enrollmentId)->delay(now()->addMinutes($first->delay_minutes));return response()->json(['enrollment_id'=>$enrollmentId,'status'=>'active'],201);
    }

    public function sequenceHistory(int $id):JsonResponse
    {
        $tenantId=auth()->user()->tenant_id;abort_unless(DB::table('email_sequences')->where('tenant_id',$tenantId)->where('id',$id)->exists(),404);return response()->json(DB::table('email_sequence_enrollments')->where('tenant_id',$tenantId)->where('sequence_id',$id)->latest('id')->paginate(25));
    }

    public function trackedLinks(Request $request): JsonResponse
    {
        $tenantId=auth()->user()->tenant_id;
        if($request->isMethod('post')){$data=$request->validate(['name'=>'required|string|max:255','destination_url'=>'required|url|max:4000','repeat_click_policy'=>'nullable|in:first,every,once_per_day','trigger_workflows'=>'nullable|boolean']);$link=TrackedLink::create(array_merge($data,['tenant_id'=>$tenantId,'public_token'=>(string)Str::uuid()]));return response()->json($link,201);}
        return response()->json(TrackedLink::where('tenant_id',$tenantId)->withCount(['visits as raw_clicks','visits as human_clicks'=>fn($q)=>$q->where('classification','human')])->latest()->paginate(25));
    }

    public function visit(Request $request, string $token, BotClassifier $classifier): RedirectResponse
    {
        $link=TrackedLink::where('public_token',$token)->firstOrFail();$ipHash=hash_hmac('sha256',(string)$request->ip(),config('app.key'));$recent=TrackedLinkVisit::where('tracked_link_id',$link->id)->where('ip_hash',$ipHash)->where('visited_at','>=',now()->subHour())->count();$result=$classifier->classify((int)$link->tenant_id,(string)$request->userAgent(),['provider_bot'=>$request->header('X-Provider-Bot')==='1','datacenter_signal'=>$request->header('X-Datacenter-IP')==='1','recent_visits'=>$recent]);
        $visitor=$request->cookie('tl_visitor') ?: (string)Str::uuid();
        $priorVisits=TrackedLinkVisit::where('tracked_link_id',$link->id)->where('visitor_token',$visitor);
        $repeatAllowed=match($link->repeat_click_policy){'first'=>!(clone $priorVisits)->exists(),'once_per_day'=>!(clone $priorVisits)->where('visited_at','>=',now()->startOfDay())->exists(),default=>true};
        $visit=TrackedLinkVisit::create(['tenant_id'=>$link->tenant_id,'tracked_link_id'=>$link->id,'visitor_token'=>$visitor,'user_agent'=>Str::limit((string)$request->userAgent(),1000,''),'ip_hash'=>$ipHash,'classification'=>$result['classification'],'classification_reason'=>$result['reason'],'raw_metadata'=>['referer'=>$request->headers->get('referer')],'visited_at'=>now()]);
        if($result['classification']==='human' && $repeatAllowed && $link->trigger_workflows) WorkflowTriggerManager::dispatchEvent('tracked_link_clicked',$link->tenant_id,['event_id'=>'tracked-link-visit:'.$visit->id,'tracked_link_id'=>$link->id]);
        return redirect()->away($link->destination_url)->withCookie(cookie('tl_visitor',$visitor,525600,null,null,true,true,false,'Lax'));
    }

    public function botRules(Request $request): JsonResponse
    {
        $tenantId=auth()->user()->tenant_id;
        if($request->isMethod('post')){$data=$request->validate(['name'=>'required|string|max:255','signal_type'=>'required|in:user_agent,provider,frequency','pattern'=>'required|string|max:1000','classification'=>'required|in:human,bot,unknown','enabled'=>'nullable|boolean']);return response()->json(AutomationBotRule::create(array_merge($data,['tenant_id'=>$tenantId])),201);}
        return response()->json(AutomationBotRule::where('tenant_id',$tenantId)->get());
    }

    public function overrideVisit(Request $request, int $id): JsonResponse
    {
        $visit=TrackedLinkVisit::where('tenant_id',auth()->user()->tenant_id)->findOrFail($id);$data=$request->validate(['classification'=>'required|in:human,bot,unknown']);$visit->update(['classification'=>$data['classification'],'classification_reason'=>'manual_override','manual_override'=>true]);return response()->json($visit);
    }

    public function troubleshoot(int $executionId): JsonResponse
    {
        $execution=\App\Models\AutomationExecution::with(['workflow','logs','enrollment'])->where('tenant_id',auth()->user()->tenant_id)->findOrFail($executionId);
        $issues=$execution->logs->where('status','failed')->map(fn($log)=>['step'=>$log->step_name,'reason'=>$log->errors?:$log->message,'suggestion'=>'Review this step configuration, provider availability, and communication permissions.'])->values();
        return response()->json(['review_only'=>true,'may_modify_workflow'=>false,'summary'=>"Execution {$execution->status} after {$execution->logs->count()} recorded steps.",'stopped_reason'=>$execution->cancellation_reason?:$execution->last_error?:$execution->completion_reason,'issues'=>$issues,'trigger_explanation'=>'The published workflow version matched its configured event before enrollment.']);
    }

    public function alertSettings(Request $request): JsonResponse
    {
        $tenantId=auth()->user()->tenant_id;
        if($request->isMethod('put')){$data=$request->validate(['enabled'=>'required|boolean','recipient_user_ids'=>'array|max:50','recipient_user_ids.*'=>'integer','repeat_suppression_minutes'=>'integer|min:1|max:10080','hourly_limit'=>'integer|min:1|max:500']);$validIds=\App\Models\User::where('tenant_id',$tenantId)->where('status','active')->whereIn('id',$data['recipient_user_ids']??[])->pluck('id')->all();$data['recipient_user_ids']=json_encode($validIds);DB::table('automation_alert_settings')->updateOrInsert(['tenant_id'=>$tenantId],array_merge($data,['created_at'=>now(),'updated_at'=>now()]));}
        $settings=DB::table('automation_alert_settings')->where('tenant_id',$tenantId)->first();return response()->json($settings?:['enabled'=>false,'recipient_user_ids'=>[],'repeat_suppression_minutes'=>60,'hourly_limit'=>20]);
    }
}
