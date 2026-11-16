<?php

namespace App\Console\Commands;

use App\Models\InboxSlaPolicy;
use App\Services\InboxSlaService;
use Illuminate\Console\Command;

class ReconcileInboxSlaCommand extends Command
{
    protected $signature = 'inbox:reconcile-sla {--tenant=}';
    protected $description = 'Reconcile authoritative inbox SLA states from provider message history';

    public function handle(InboxSlaService $service): int
    {
        $tenants = InboxSlaPolicy::where('is_active', true)->when($this->option('tenant'), fn ($q) => $q->where('tenant_id', $this->option('tenant')))->distinct()->pluck('tenant_id');
        foreach ($tenants as $tenantId) $service->reconcileTenant((int) $tenantId);
        $this->info("Reconciled {$tenants->count()} tenant inbox SLA configuration(s).");
        return self::SUCCESS;
    }
}

