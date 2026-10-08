<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScraperSearch;
use App\Services\Scraper\ScraperService;
use Illuminate\Console\Command;

class ProcessScraperCommand extends Command
{
    protected $signature = 'scraper:process {search_id : The ID of the ScraperSearch to process}';
    protected $description = 'Process a business scraper search and extract emails';

    public function handle(ScraperService $scraperService): int
    {
        $searchId = (int) $this->argument('search_id');
        $search = ScraperSearch::find($searchId);

        if (!$search) {
            $this->error("Scraper search ID {$searchId} not found.");
            return Command::FAILURE;
        }

        $this->info("Processing search #{$searchId}: '{$search->keyword}' in '{$search->location}'...");
        
        try {
            $scraperService->processSearch($search);
            $search->refresh();
            $this->info("Completed! Found: {$search->total_found}, Processed: {$search->total_processed}, Emails: {$search->total_emails_found}, Duplicates: {$search->total_duplicates}");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Search processing failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
