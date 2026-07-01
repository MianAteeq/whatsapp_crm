<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\WhatsappTemplate;
use App\Services\Workflow\WorkflowTriggerManager;

class WhatsappTemplateObserver
{
    /**
     * Handle the WhatsappTemplate "created" event.
     */
    public function created(WhatsappTemplate $template): void
    {
        WorkflowTriggerManager::dispatchEvent('template_created', $template->tenant_id, [
            'template' => $template
        ]);
    }
}
