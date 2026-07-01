<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Contact;
use App\Services\Workflow\WorkflowTriggerManager;

class ContactObserver
{
    /**
     * Handle the Contact "created" event.
     */
    public function created(Contact $contact): void
    {
        WorkflowTriggerManager::dispatchEvent('contact_created', $contact->tenant_id, [
            'contact' => $contact
        ]);
    }

    /**
     * Handle the Contact "updated" event.
     */
    public function updated(Contact $contact): void
    {
        WorkflowTriggerManager::dispatchEvent('contact_updated', $contact->tenant_id, [
            'contact' => $contact,
            'dirty' => array_keys($contact->getDirty())
        ]);
    }
}
