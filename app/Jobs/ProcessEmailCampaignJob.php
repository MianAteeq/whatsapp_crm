<?php

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEmailCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        public int $campaignId
    ) {}

    public function handle(): void
    {
        $campaign = EmailCampaign::find($this->campaignId);
        if (!$campaign) {
            Log::warning("[ProcessEmailCampaignJob] Campaign {$this->campaignId} not found.");
            return;
        }

        // Only process if in SENDING state
        if ($campaign->status !== EmailCampaign::STATUS_SENDING) {
            Log::info("[ProcessEmailCampaignJob] Campaign {$this->campaignId} is in status '{$campaign->status}'. Skipping job.");
            return;
        }

        $setting = EmailSetting::where('tenant_id', $campaign->tenant_id)->first();
        $rateLimitPerMinute = (int) ($setting?->rate_limit_per_minute ?: 60);
        $batchSize = min(50, max(10, $rateLimitPerMinute));

        // Get all pending recipients
        $pendingRecipientIds = EmailCampaignRecipient::where('campaign_id', $this->campaignId)
            ->whereIn('status', [EmailCampaignRecipient::STATUS_PENDING, EmailCampaignRecipient::STATUS_QUEUED])
            ->pluck('id')
            ->all();

        if (empty($pendingRecipientIds)) {
            // No pending recipients left
            $campaign->recalculateMetrics();
            $campaign->update([
                'status' => EmailCampaign::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
            Log::info("[ProcessEmailCampaignJob] No pending recipients for campaign {$this->campaignId}. Marked completed.");
            return;
        }

        // Mark them as queued
        EmailCampaignRecipient::whereIn('id', $pendingRecipientIds)
            ->update(['status' => EmailCampaignRecipient::STATUS_QUEUED]);

        // Chunk into batches and dispatch with delay
        $chunks = array_chunk($pendingRecipientIds, $batchSize);
        $secondsPerBatch = (int) ceil(($batchSize / $rateLimitPerMinute) * 60);

        foreach ($chunks as $index => $chunkIds) {
            $delay = $index * $secondsPerBatch;
            
            SendEmailBatchJob::dispatch($this->campaignId, $chunkIds)
                ->delay(now()->addSeconds($delay));
        }

        Log::info("[ProcessEmailCampaignJob] Dispatched " . count($chunks) . " batches (" . count($pendingRecipientIds) . " recipients) for campaign {$this->campaignId}.");
    }
}
