<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InboxConversationState;
use App\Models\InboxNote;
use App\Models\InboxSlaPolicy;
use App\Models\InboxUserState;
use App\Models\Message;
use App\Models\ReplySnippet;
use App\Models\SocialAccount;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InboxSlaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PhaseThreeInboxDemoSeeder extends Seeder
{
    public function run(): void
    {
        $requestedTenantId = env('DEMO_TENANT_ID');
        $tenant = $requestedTenantId
            ? Tenant::find($requestedTenantId)
            : Tenant::query()->oldest('id')->first();

        if (!$tenant) {
            throw new RuntimeException('Create a tenant before running PhaseThreeInboxDemoSeeder.');
        }

        $user = User::query()
            ->where('tenant_id', $tenant->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->oldest('id')
            ->first();

        if (!$user) {
            throw new RuntimeException("Tenant {$tenant->id} needs at least one user before demo inbox data can be seeded.");
        }

        DB::transaction(function () use ($tenant, $user): void {
            $policies = $this->seedPolicies((int) $tenant->id);
            $contacts = $this->seedContacts((int) $tenant->id);
            $this->seedWhatsApp((int) $tenant->id, (int) $user->id, $contacts);
            $this->seedSocial((int) $tenant->id, (int) $user->id, $contacts);
            $this->seedSnippets((int) $tenant->id, (int) $user->id);
        });

        app(InboxSlaService::class)->reconcileTenant((int) $tenant->id);

        $this->command?->info(
            "Phase 3 demo inbox data is ready for tenant {$tenant->id} ({$tenant->name}) and user {$user->email}."
        );
    }

    private function seedPolicies(int $tenantId): array
    {
        $settings = [
            'whatsapp' => [30, 10],
            'facebook' => [45, 15],
            'instagram' => [60, 15],
        ];

        $policies = [];
        foreach ($settings as $channel => [$responseMinutes, $warningMinutes]) {
            $policies[$channel] = InboxSlaPolicy::updateOrCreate(
                ['tenant_id' => $tenantId, 'channel' => $channel],
                [
                    'response_minutes' => $responseMinutes,
                    'warning_minutes' => $warningMinutes,
                    'continuous_clock' => true,
                    'human_reply_stops_clock' => true,
                    'ai_reply_stops_clock' => false,
                    'workflow_reply_stops_clock' => false,
                    'is_active' => true,
                ]
            );
        }

        return $policies;
    }

    private function seedContacts(int $tenantId): array
    {
        $definitions = [
            'ayesha' => ['Ayesha Khan', '+15550101001', 'ayesha.demo@example.test', 'Northstar Dental', 'Office Manager'],
            'omar' => ['Omar Siddiqui', '+15550101002', 'omar.demo@example.test', 'Cedar Realty', 'Property Consultant'],
            'nadia' => ['Nadia Ahmed', '+15550101003', 'nadia.demo@example.test', 'Bright Home Services', 'Operations Lead'],
            'bilal' => ['Bilal Raza', '+15550101004', 'bilal.demo@example.test', 'Vertex Fitness', 'Founder'],
            'sara' => ['Sara Malik', '+15550101005', 'sara.demo@example.test', 'Willow Events', 'Event Coordinator'],
            'hamza' => ['Hamza Noor', '+15550101006', 'hamza.demo@example.test', 'Summit Legal', 'Practice Manager'],
            'mehak' => ['Mehak Ali', '+15550101007', 'mehak.demo@example.test', 'Bloom Skincare', 'Marketing Manager'],
        ];

        $contacts = [];
        foreach ($definitions as $key => [$name, $phone, $email, $company, $jobTitle]) {
            $contacts[$key] = Contact::updateOrCreate(
                ['tenant_id' => $tenantId, 'phone' => $phone],
                [
                    'name' => $name,
                    'first_name' => strtok($name, ' '),
                    'last_name' => trim(substr($name, strlen(strtok($name, ' ')))),
                    'email' => $email,
                    'company' => $company,
                    'job_title' => $jobTitle,
                    'contact_type' => 'lead',
                    'source' => 'demo_seed',
                    'notes' => 'Phase 3 unified inbox demonstration contact.',
                    'status' => 'active',
                ]
            );
        }

        return $contacts;
    }

    private function seedWhatsApp(int $tenantId, int $userId, array $contacts): void
    {
        $definitions = [
            [
                'contact' => 'ayesha', 'wa_id' => '15550101001', 'status' => 'open', 'priority' => 'normal',
                'assigned' => $userId, 'starred' => true, 'following' => true, 'unread' => 1,
                'messages' => [
                    ['demo-wa-ayesha-1', 'incoming', 'Hi, can you tell me which appointment times are available tomorrow?', 10, null],
                ],
            ],
            [
                'contact' => 'omar', 'wa_id' => '15550101002', 'status' => 'open', 'priority' => 'high',
                'assigned' => $userId, 'starred' => false, 'following' => true, 'unread' => 2,
                'messages' => [
                    ['demo-wa-omar-1', 'incoming', 'I need pricing for the professional plan for a five-person team.', 25, null],
                    ['demo-wa-omar-2', 'incoming', 'Can someone send the details today?', 22, null],
                ],
            ],
            [
                'contact' => 'nadia', 'wa_id' => '15550101003', 'status' => 'open', 'priority' => 'urgent',
                'assigned' => null, 'starred' => true, 'following' => false, 'unread' => 3,
                'messages' => [
                    ['demo-wa-nadia-1', 'incoming', 'Our booking page is showing an error. We need help as soon as possible.', 80, null],
                    ['demo-wa-nadia-2', 'incoming', 'The issue is still happening for all customers.', 75, null],
                ],
            ],
            [
                'contact' => 'bilal', 'wa_id' => '15550101004', 'status' => 'pending', 'priority' => 'normal',
                'assigned' => $userId, 'starred' => false, 'following' => false, 'unread' => 0,
                'messages' => [
                    ['demo-wa-bilal-1', 'incoming', 'Could you confirm whether my onboarding call is booked?', 48, null],
                    ['demo-wa-bilal-2', 'outgoing', 'Yes, Bilal. Your onboarding call is confirmed for Thursday at 2:00 PM.', 40, 'human'],
                ],
            ],
            [
                'contact' => 'sara', 'wa_id' => '15550101005', 'status' => 'resolved', 'priority' => 'low',
                'assigned' => null, 'starred' => false, 'following' => false, 'unread' => 0,
                'messages' => [
                    ['demo-wa-sara-1', 'incoming', 'Thank you, everything is working now.', 120, null],
                ],
            ],
        ];

        foreach ($definitions as $definition) {
            $contact = $contacts[$definition['contact']];
            $last = end($definition['messages']);
            $conversation = Conversation::updateOrCreate(
                ['tenant_id' => $tenantId, 'wa_id' => $definition['wa_id']],
                [
                    'contact_id' => $contact->id,
                    'last_message' => $last[2],
                    'last_message_at' => now()->subMinutes($last[3]),
                    'unread_count' => $definition['unread'],
                ]
            );

            foreach ($definition['messages'] as [$providerId, $direction, $body, $minutesAgo, $originType]) {
                Message::updateOrCreate(
                    ['tenant_id' => $tenantId, 'message_id' => $providerId],
                    [
                        'conversation_id' => $conversation->id,
                        'sender_user_id' => $direction === 'outgoing' ? $userId : null,
                        'direction' => $direction,
                        'origin_type' => $originType,
                        'message' => $body,
                        'type' => 'text',
                        'status' => $direction === 'outgoing' ? 'delivered' : 'received',
                        'payload' => ['demo' => true],
                        'created_at' => now()->subMinutes($minutesAgo),
                        'updated_at' => now()->subMinutes($minutesAgo),
                    ]
                );
            }

            $this->seedConversationState(
                $tenantId,
                $userId,
                'whatsapp',
                (int) $conversation->id,
                $definition
            );
        }

        $firstConversation = Conversation::where('tenant_id', $tenantId)->where('wa_id', '15550101001')->firstOrFail();
        InboxNote::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'channel' => 'whatsapp',
                'source_id' => $firstConversation->id,
                'content' => 'Demo note: this customer prefers morning appointments and WhatsApp follow-ups.',
            ],
            []
        );
    }

    private function seedSocial(int $tenantId, int $userId, array $contacts): void
    {
        $accounts = [];
        foreach ([
            'facebook' => ['demo-facebook-page', 'Demo Business Facebook', 'demo.business.fb'],
            'instagram' => ['demo-instagram-account', 'Demo Business Instagram', 'demo.business.ig'],
        ] as $platform => [$accountId, $name, $username]) {
            $accounts[$platform] = SocialAccount::updateOrCreate(
                ['tenant_id' => $tenantId, 'platform' => $platform, 'account_id' => $accountId],
                [
                    'name' => $name,
                    'username' => $username,
                    'access_token' => 'demo-token-do-not-use',
                    'is_active' => false,
                    'category' => 'Demo account',
                    'followers_count' => 1250,
                    'connected_at' => now()->subDays(30),
                    'metadata' => ['demo' => true],
                ]
            );
        }

        $definitions = [
            [
                'platform' => 'facebook', 'thread' => 'demo-fb-hamza', 'contact' => 'hamza',
                'name' => 'Hamza Noor', 'username' => 'hamza.noor', 'status' => 'open', 'priority' => 'high',
                'assigned' => $userId, 'starred' => false, 'following' => true, 'unread' => 1,
                'messages' => [
                    ['demo-fb-hamza-1', 'customer', 'Do you offer CRM migration from our current platform?', 18],
                ],
            ],
            [
                'platform' => 'facebook', 'thread' => 'demo-fb-unresolved', 'contact' => null,
                'name' => 'Facebook Visitor', 'username' => 'visitor.demo', 'status' => 'open', 'priority' => 'normal',
                'assigned' => null, 'starred' => false, 'following' => false, 'unread' => 1,
                'messages' => [
                    ['demo-fb-visitor-1', 'customer', 'I saw your post. Where can I book a product demo?', 70],
                ],
            ],
            [
                'platform' => 'instagram', 'thread' => 'demo-ig-mehak', 'contact' => 'mehak',
                'name' => 'Mehak Ali', 'username' => 'mehak.bloom', 'status' => 'pending', 'priority' => 'normal',
                'assigned' => $userId, 'starred' => true, 'following' => false, 'unread' => 0,
                'messages' => [
                    ['demo-ig-mehak-1', 'customer', 'Can you share examples of campaigns for beauty brands?', 35],
                    ['demo-ig-mehak-2', 'agent', 'Absolutely. I will send two relevant examples and pricing options.', 27],
                ],
            ],
            [
                'platform' => 'instagram', 'thread' => 'demo-ig-urgent', 'contact' => null,
                'name' => 'Studio Twenty', 'username' => 'studio.twenty.demo', 'status' => 'open', 'priority' => 'urgent',
                'assigned' => null, 'starred' => true, 'following' => true, 'unread' => 4,
                'messages' => [
                    ['demo-ig-studio-1', 'customer', 'We need to launch next week. Can your team set everything up?', 95],
                ],
            ],
        ];

        foreach ($definitions as $definition) {
            $account = $accounts[$definition['platform']];
            $contact = $definition['contact'] ? $contacts[$definition['contact']] : null;
            $last = end($definition['messages']);
            $conversation = SocialConversation::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'platform' => $definition['platform'],
                    'platform_conversation_id' => $definition['thread'],
                ],
                [
                    'social_account_id' => $account->id,
                    'contact_id' => $contact?->id,
                    'assigned_to_user_id' => $definition['assigned'],
                    'conversation_type' => 'direct_message',
                    'participant_id' => "participant-{$definition['thread']}",
                    'participant_name' => $definition['name'],
                    'participant_username' => $definition['username'],
                    'status' => $definition['status'],
                    'priority' => $definition['priority'],
                    'is_unread' => $definition['unread'] > 0,
                    'unread_count' => $definition['unread'],
                    'last_message_at' => now()->subMinutes($last[3]),
                    'last_message_preview' => $last[2],
                    'last_message_sender' => $last[1],
                    'metadata' => ['demo' => true],
                ]
            );

            foreach ($definition['messages'] as [$providerId, $senderType, $body, $minutesAgo]) {
                SocialMessage::updateOrCreate(
                    ['tenant_id' => $tenantId, 'platform_message_id' => $providerId],
                    [
                        'social_conversation_id' => $conversation->id,
                        'social_account_id' => $account->id,
                        'sender_type' => $senderType,
                        'sender_user_id' => $senderType === 'agent' ? $userId : null,
                        'sender_id' => $senderType === 'agent' ? "agent-{$userId}" : "participant-{$definition['thread']}",
                        'sender_name' => $senderType === 'agent' ? User::find($userId)?->name : $definition['name'],
                        'message_text' => $body,
                        'status' => $senderType === 'agent' ? 'delivered' : 'read',
                        'sent_at' => now()->subMinutes($minutesAgo),
                        'delivered_at' => now()->subMinutes(max($minutesAgo - 1, 0)),
                        'metadata' => ['demo' => true],
                    ]
                );
            }

            $this->seedConversationState(
                $tenantId,
                $userId,
                $definition['platform'],
                (int) $conversation->id,
                $definition
            );
        }

        $urgent = SocialConversation::where('tenant_id', $tenantId)
            ->where('platform_conversation_id', 'demo-ig-urgent')
            ->firstOrFail();
        InboxNote::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'channel' => 'instagram',
                'source_id' => $urgent->id,
                'content' => 'Demo note: qualify the launch date and budget before assigning this lead.',
            ],
            []
        );
    }

    private function seedConversationState(
        int $tenantId,
        int $userId,
        string $channel,
        int $sourceId,
        array $definition
    ): void {
        InboxConversationState::updateOrCreate(
            ['tenant_id' => $tenantId, 'channel' => $channel, 'source_id' => $sourceId],
            [
                'assigned_to_user_id' => $definition['assigned'],
                'status' => $definition['status'],
                'priority' => $definition['priority'],
            ]
        );

        InboxUserState::updateOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $userId, 'channel' => $channel, 'source_id' => $sourceId],
            [
                'is_starred' => $definition['starred'],
                'is_following' => $definition['following'],
                'unread_override' => $definition['unread'] > 0,
                'last_viewed_at' => $definition['unread'] > 0 ? null : now()->subMinutes(5),
            ]
        );
    }

    private function seedSnippets(int $tenantId, int $userId): void
    {
        foreach ([
            ['Demo: Welcome', 'General', 'Hi {{contact.first_name}}, thanks for contacting us. My name is {{agent.name}}. How can I help today?'],
            ['Demo: Book a call', 'Sales', 'Hi {{contact.first_name}}, I would be happy to arrange a quick call. Which day and time works best for you?'],
            ['Demo: Pricing follow-up', 'Sales', 'Hi {{contact.first_name}}, thanks for your interest in our plans. I can recommend the best option after learning your team size and goals.'],
            ['Demo: Issue received', 'Support', 'Thanks for reporting this, {{contact.first_name}}. I have recorded the issue and will update you as soon as we confirm the next step.'],
        ] as [$title, $category, $body]) {
            ReplySnippet::updateOrCreate(
                ['tenant_id' => $tenantId, 'title' => $title],
                [
                    'created_by_user_id' => $userId,
                    'category' => $category,
                    'body' => $body,
                    'visibility' => 'shared',
                    'is_active' => true,
                ]
            );
        }
    }
}
