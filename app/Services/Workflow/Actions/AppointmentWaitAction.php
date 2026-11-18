<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Models\Appointment;
use App\Services\Workflow\WorkflowContext;
use Carbon\Carbon;

final class AppointmentWaitAction implements ActionHandlerInterface
{
    public function execute(WorkflowContext $context, array $config): ActionResponse
    {
        $appointment=Appointment::where('tenant_id',$context->getWorkflow()->tenant_id)->find($config['appointment_id']??0);
        if(!$appointment)return ActionResponse::failed('Appointment lookup failed.');
        $minutes=(int)($config['offset_minutes']??0);$anchor=($config['anchor']??'starts_at')==='ends_at'?$appointment->ends_at:$appointment->starts_at;
        $resume=Carbon::parse($anchor)->addMinutes($minutes);if($resume->isPast())$resume=now();
        return ActionResponse::wait($resume,'Waiting relative to the configured appointment time.');
    }
}
