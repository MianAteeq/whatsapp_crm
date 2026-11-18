<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Services\Workflow\WorkflowContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class EventWaitAction implements ActionHandlerInterface
{
    public function execute(WorkflowContext $context, array $config): ActionResponse
    {
        $timeout = Carbon::now()->addMinutes(max(1, (int)($config['timeout_minutes'] ?? 1440)));
        DB::table('automation_event_waits')->updateOrInsert(
            ['execution_id'=>$context->getExecution()->id,'node_id'=>$context->getExecution()->current_node_id],
            ['tenant_id'=>$context->getWorkflow()->tenant_id,'event_type'=>$config['event_type']??'incoming_message','match_config'=>json_encode($config['match']??[]),'timeout_at'=>$timeout,'status'=>'waiting','created_at'=>now(),'updated_at'=>now()]
        );
        return ActionResponse::wait($timeout, 'Waiting for a matching customer event or timeout.');
    }
}
