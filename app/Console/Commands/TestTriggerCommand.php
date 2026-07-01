<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\Conversation;
use App\Services\Workflow\WorkflowTriggerManager;

class TestTriggerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'automation:test-trigger {--type=contact_created} {--phone=1234567890} {--text=hello}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Trigger and run testing executions for active workflows from command line.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->option('type');
        $phone = $this->option('phone');
        $text = $this->option('text');

        $tenant = Tenant::first();
        if (!$tenant) {
            $this->error("No tenant workspace found. Seed the database first.");
            return 1;
        }

        $this->info("Simulating trigger: [{$type}] for Phone: [{$phone}]");

        // 1. Create or Find Contact
        $contact = Contact::firstOrCreate(
            ['phone' => $phone, 'tenant_id' => $tenant->id],
            ['name' => 'Trigger Testing Contact', 'birthday' => now()->toDateString()]
        );

        $payload = ['contact' => $contact];

        // 2. Mock Message if testing incoming message or keyword
        if ($type === 'incoming_message' || $type === 'keyword') {
            $conversation = Conversation::firstOrCreate(
                ['tenant_id' => $tenant->id, 'contact_id' => $contact->id],
                ['wa_id' => $phone, 'last_message' => $text, 'last_message_at' => now()]
            );

            $message = Message::create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'message_id' => 'test_' . uniqid(),
                'direction' => 'incoming',
                'message' => $text,
                'type' => 'text',
                'status' => 'received'
            ]);

            $payload['message'] = $message;
        }

        // Fired dispatch event
        WorkflowTriggerManager::dispatchEvent($type, $tenant->id, $payload);

        $this->info("Simulated trigger dispatched successfully. Run `php artisan queue:work` if your queue is not running in sync mode.");
        return 0;
    }
}
