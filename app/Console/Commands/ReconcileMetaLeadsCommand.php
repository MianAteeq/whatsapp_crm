<?php

namespace App\Console\Commands;

use App\Models\MetaLeadForm;
use App\Models\MetaLeadSubmission;
use App\Services\Social\MetaLeadAdsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileMetaLeadsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'leads:reconcile {--tenant= : Reconcile only a specific tenant ID} {--limit=50 : Max leads to check per form}';

    /**
     * The console command description.
     */
    protected $description = 'Reconciles missed Meta Lead Ads from Facebook & Instagram Graph API';

    /**
     * Execute the console command.
     */
    public function handle(MetaLeadAdsService $service): int
    {
        $this->info("Starting Meta Lead Ads reconciliation process...");

        $query = MetaLeadForm::with('socialAccount')
            ->where('is_active', true)
            ->where('auto_sync', true);

        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tenantId);
        }

        $forms = $query->get();
        $this->info("Found {$forms->count()} active Lead Ads forms to reconcile.");

        $totalRecovered = 0;
        $limit = (int) $this->option('limit');

        foreach ($forms as $form) {
            $this->line("Reconciling Form: {$form->name} (Form ID: {$form->form_id})...");

            try {
                $result = $service->importHistoricalLeads($form->tenant_id, $form->id, $limit);
                $imported = $result['imported'] ?? 0;
                $duplicates = $result['duplicates'] ?? 0;
                $totalRecovered += $imported;

                $this->info(" -> Imported: {$imported} new leads, {$duplicates} duplicates skipped.");
            } catch (\Throwable $e) {
                $this->error(" -> Failed for form {$form->id}: " . $e->getMessage());
                Log::warning("[ReconcileMetaLeadsCommand] Form {$form->id} reconciliation error: " . $e->getMessage());
            }
        }

        $this->info("Reconciliation complete. Total recovered leads: {$totalRecovered}");
        return Command::SUCCESS;
    }
}

