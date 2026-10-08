<?php

namespace App\Console\Commands;

use App\Models\EmailCampaign;
use App\Services\Email\EmailCampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessScheduledEmailCampaigns extends Command
{
    protected $signature = 'email:process-scheduled';
    protected $description = 'Trigger sending for scheduled email campaigns that have reached their target time';

    public function handle(): int
    {
        $campaigns = EmailCampaign::where('status', EmailCampaign::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($campaigns->isEmpty()) {
            $this->info('No pending scheduled campaigns to process.');
            return 0;
        }

        $this->info("Found {$campaigns->count()} scheduled campaigns to launch.");

        foreach ($campaigns as $campaign) {
            $this->line("Launching campaign: {$campaign->name} (ID: {$campaign->id})...");
            try {
                EmailCampaignService::launchCampaign($campaign, true);
                $this->info("Campaign {$campaign->id} launched successfully.");
            } catch (\Throwable $e) {
                Log::error("[ProcessScheduledEmailCampaigns] Failed to launch campaign {$campaign->id}: " . $e->getMessage());
                $this->error("Failed to launch campaign {$campaign->id}: " . $e->getMessage());
            }
        }

        return 0;
    }
}
