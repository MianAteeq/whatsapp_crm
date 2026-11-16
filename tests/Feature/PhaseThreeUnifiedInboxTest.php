<?php

namespace Tests\Feature;

use App\Models\{Contact, Conversation, InboxSlaPolicy, Message, Tenant, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseThreeUnifiedInboxTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email = 'inbox-owner@test.local'): User
    {
        $tenant = Tenant::create(['name' => 'Inbox Test', 'timezone' => 'UTC']);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'Inbox Owner', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active']);
        $tenant->update(['owner_id' => $user->id]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function whatsappConversation(User $owner, string $name = 'Amina Customer'): Conversation
    {
        $contact = Contact::create(['tenant_id' => $owner->tenant_id, 'name' => $name, 'first_name' => 'Amina', 'phone' => '+923001234567', 'company' => 'Amina Co', 'status' => 'active']);
        $conversation = Conversation::create(['tenant_id' => $owner->tenant_id, 'contact_id' => $contact->id, 'wa_id' => '923001234567', 'last_message' => 'I need help', 'last_message_at' => now(), 'unread_count' => 1]);
        Message::create(['tenant_id' => $owner->tenant_id, 'conversation_id' => $conversation->id, 'message_id' => 'wa-inbound-1', 'direction' => 'incoming', 'message' => 'I need help', 'type' => 'text', 'status' => 'received']);
        return $conversation;
    }

    public function test_unified_list_is_tenant_scoped_and_uses_stable_channel_identity(): void
    {
        $ownerA = $this->owner();
        $conversation = $this->whatsappConversation($ownerA);
        $this->getJson('/api/inbox/conversations')->assertOk()->assertJsonPath('data.0.channel', 'whatsapp')->assertJsonPath('data.0.source_id', $conversation->id)->assertJsonPath('data.0.identity_status', 'linked');
        $this->getJson("/api/inbox/conversations/whatsapp/{$conversation->id}")->assertOk()->assertJsonPath('conversation.can_send_freeform', true)->assertJsonPath('messages.0.body', 'I need help');

        $this->owner('other-inbox-owner@test.local');
        $this->getJson('/api/inbox/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/inbox/conversations/whatsapp/{$conversation->id}")->assertNotFound();
    }

    public function test_assignment_follow_star_and_read_state_persist_per_tenant_user(): void
    {
        $owner = $this->owner();
        $conversation = $this->whatsappConversation($owner);
        $this->patchJson("/api/inbox/conversations/whatsapp/{$conversation->id}/state", ['assigned_to_user_id' => $owner->id, 'is_starred' => true, 'is_following' => true, 'is_unread' => false, 'priority' => 'high'])->assertOk();
        $this->assertDatabaseHas('inbox_conversation_states', ['tenant_id' => $owner->tenant_id, 'channel' => 'whatsapp', 'source_id' => $conversation->id, 'assigned_to_user_id' => $owner->id, 'priority' => 'high']);
        $this->assertDatabaseHas('inbox_user_states', ['user_id' => $owner->id, 'is_starred' => 1, 'is_following' => 1, 'unread_override' => 0]);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'unread_count' => 0]);
    }

    public function test_snippet_variables_are_server_rendered_and_unknown_variables_are_warned(): void
    {
        $owner = $this->owner();
        $conversation = $this->whatsappConversation($owner);
        $snippet = $this->postJson('/api/inbox/snippets', ['title' => 'Welcome', 'category' => 'Support', 'visibility' => 'shared', 'body' => 'Hi {{contact.first_name}} from {{contact.company}}. I am {{agent.name}}. {{contact.secret}}'])->assertOk()->json('snippet');
        $this->postJson("/api/inbox/conversations/whatsapp/{$conversation->id}/render-snippet", ['snippet_id' => $snippet['id']])->assertOk()->assertJsonPath('body', 'Hi Amina from Amina Co. I am Inbox Owner. {{contact.secret}}')->assertJsonPath('warnings.0', 'Unsupported variable: contact.secret');
    }

    public function test_sla_is_derived_from_message_history_and_human_origin(): void
    {
        $owner = $this->owner();
        $conversation = $this->whatsappConversation($owner);
        Message::where('conversation_id', $conversation->id)->update(['created_at' => now()->subMinutes(90)]);
        InboxSlaPolicy::create(['tenant_id' => $owner->tenant_id, 'channel' => 'whatsapp', 'response_minutes' => 60, 'warning_minutes' => 15, 'continuous_clock' => true, 'human_reply_stops_clock' => true, 'is_active' => true]);
        app(\App\Services\InboxSlaService::class)->reconcile($owner->tenant_id, 'whatsapp', $conversation->id);
        $this->getJson('/api/inbox/conversations?view=sla_overdue')->assertOk()->assertJsonPath('data.0.sla_status', 'overdue');

        Message::create(['tenant_id' => $owner->tenant_id, 'conversation_id' => $conversation->id, 'sender_user_id' => $owner->id, 'message_id' => 'wa-outbound-1', 'direction' => 'outgoing', 'origin_type' => 'human', 'message' => 'I can help', 'type' => 'text', 'status' => 'sent', 'created_at' => now()->subMinutes(20)]);
        $this->getJson('/api/inbox/conversations')->assertOk()->assertJsonPath('data.0.sla_status', 'met');
    }

    public function test_manual_follow_up_reuses_crm_tasks_with_conversation_link(): void
    {
        $owner = $this->owner();
        $conversation = $this->whatsappConversation($owner);
        $this->postJson("/api/inbox/conversations/whatsapp/{$conversation->id}/tasks", ['title' => 'Call customer tomorrow', 'owner_id' => $owner->id, 'priority' => 'high', 'due_at' => now()->addDay()->toISOString()])->assertCreated();
        $this->assertDatabaseHas('crm_tasks', ['tenant_id' => $owner->tenant_id, 'conversation_channel' => 'whatsapp', 'conversation_source_id' => $conversation->id, 'title' => 'Call customer tomorrow']);
    }
}

