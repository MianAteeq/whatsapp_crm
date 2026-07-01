<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Contact;
use App\Services\Workflow\WorkflowTriggerManager;
use Carbon\Carbon;

class CheckBirthdaysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'automation:check-birthdays';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Finds contacts celebrating birthdays today and dispatches matching workflows.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = Carbon::today();
        $this->info("Checking contact birthdays for day: {$today->format('m-d')}");

        $contacts = Contact::whereNotNull('birthday')
            ->whereMonth('birthday', $today->month)
            ->whereDay('birthday', $today->day)
            ->get();

        $count = 0;
        foreach ($contacts as $contact) {
            WorkflowTriggerManager::dispatchEvent('birthday', $contact->tenant_id, [
                'contact' => $contact
            ]);
            $count++;
        }

        $this->info("Successfully triggered birthday workflows for {$count} contact(s).");
        return 0;
    }
}
