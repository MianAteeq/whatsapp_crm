<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ScraperSearch;
use App\Services\Scraper\ScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessScraperSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes timeout for large-scale discovery and enrichment
    public int $tries = 1;

    public function __construct(
        public int $searchId
    ) {}

    public function handle(ScraperService $scraperService): void
    {
        $search = ScraperSearch::find($this->searchId);
        if (!$search) {
            Log::channel('scraper')->warning("Job abort: ScraperSearch ID {$this->searchId} not found");
            return;
        }

        if ($search->status === 'completed' || $search->status === 'cancelled') {
            Log::channel('scraper')->info("Job skipped: ScraperSearch ID {$this->searchId} already in status '{$search->status}'");
            return;
        }

        $scraperService->processSearch($search);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('scraper')->error("ProcessScraperSearchJob failed for search ID {$this->searchId}: " . $exception->getMessage());
        $search = ScraperSearch::find($this->searchId);
        if ($search) {
            $search->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);
        }
    }
}
