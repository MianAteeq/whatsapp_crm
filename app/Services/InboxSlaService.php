<?php

namespace App\Services;

use App\Models\{InboxSlaEvent, InboxSlaPolicy, InboxSlaState, Message, SocialMessage};

class InboxSlaService
{
    public function reconcileTenant(int $tenantId, int $limit = 500): void
    {
        $channels = InboxSlaPolicy::where('tenant_id', $tenantId)->where('is_active', true)->pluck('channel');
        foreach ($channels as $channel) {
            $conversationStates = \App\Models\InboxConversationState::where('tenant_id', $tenantId)->where('channel', $channel)->pluck('status', 'source_id');
            if ($channel === 'whatsapp') {
                $sources = \App\Models\Conversation::where('tenant_id', $tenantId)->latest('last_message_at')->limit($limit)->pluck('id');
                foreach ($sources as $sourceId) $this->reconcile($tenantId, $channel, (int) $sourceId, $conversationStates[$sourceId] ?? 'open');
            } else {
                $sources = \App\Models\SocialConversation::where('tenant_id', $tenantId)->where('platform', $channel)->latest('last_message_at')->limit($limit)->get(['id', 'status']);
                foreach ($sources as $source) $this->reconcile($tenantId, $channel, (int) $source->id, $conversationStates[$source->id] ?? $source->status);
            }
        }
    }

    public function reconcile(int $tenantId, string $channel, int $sourceId, ?string $conversationStatus = 'open'): ?InboxSlaState
    {
        $policy = InboxSlaPolicy::where('tenant_id', $tenantId)->where('channel', $channel)->where('is_active', true)->first();
        if (!$policy) return null;

        if ($channel === 'whatsapp') {
            $inbound = Message::where('tenant_id', $tenantId)->where('conversation_id', $sourceId)->where('direction', 'incoming')->latest('created_at')->first();
            $response = $inbound ? Message::where('tenant_id', $tenantId)->where('conversation_id', $sourceId)->where('direction', 'outgoing')->where('created_at', '>', $inbound->created_at)->where(function ($query) use ($policy) {
                $query->where('origin_type', 'human');
                if ($policy->ai_reply_stops_clock) $query->orWhere('origin_type', 'ai');
                if ($policy->workflow_reply_stops_clock) $query->orWhere('origin_type', 'workflow');
            })->oldest('created_at')->first() : null;
            $inboundAt = $inbound?->created_at;
            $inboundKey = $inbound ? (string) ($inbound->message_id ?: $inbound->id) : null;
            $respondedAt = $response?->created_at;
        } else {
            $inbound = SocialMessage::where('tenant_id', $tenantId)->where('social_conversation_id', $sourceId)->where('sender_type', 'customer')->latest('sent_at')->first();
            $response = $inbound ? SocialMessage::where('tenant_id', $tenantId)->where('social_conversation_id', $sourceId)->where('sent_at', '>', $inbound->sent_at)->where(function ($query) use ($policy) {
                $query->where(fn ($human) => $human->where('sender_type', 'agent')->whereNotNull('sender_user_id'));
                if ($policy->ai_reply_stops_clock || $policy->workflow_reply_stops_clock) $query->orWhere('sender_type', 'system');
            })->oldest('sent_at')->first() : null;
            $inboundAt = $inbound?->sent_at;
            $inboundKey = $inbound ? (string) ($inbound->platform_message_id ?: $inbound->id) : null;
            $respondedAt = $response?->sent_at;
        }

        $dueAt = $inboundAt?->copy()->addMinutes($policy->response_minutes);
        $status = 'inactive';
        if ($inboundAt && $respondedAt) $status = 'met';
        elseif ($inboundAt && $conversationStatus === 'resolved') $status = 'paused';
        elseif ($inboundAt && $dueAt->isPast()) $status = 'overdue';
        elseif ($inboundAt && now()->gte($dueAt->copy()->subMinutes(min($policy->warning_minutes, $policy->response_minutes)))) $status = 'warning';
        elseif ($inboundAt) $status = 'running';

        $existing = InboxSlaState::where('tenant_id', $tenantId)->where('channel', $channel)->where('source_id', $sourceId)->first();
        $previousStatus = $existing?->status;
        $state = InboxSlaState::updateOrCreate(
            ['tenant_id' => $tenantId, 'channel' => $channel, 'source_id' => $sourceId],
            ['policy_id' => $policy->id, 'last_inbound_message_key' => $inboundKey, 'started_at' => $inboundAt, 'due_at' => $dueAt, 'responded_at' => $respondedAt, 'status' => $status]
        );
        if ($previousStatus !== $status) InboxSlaEvent::create(['tenant_id' => $tenantId, 'channel' => $channel, 'source_id' => $sourceId, 'event_type' => 'status_changed', 'metadata' => ['from' => $previousStatus, 'to' => $status, 'due_at' => $dueAt?->toIso8601String()], 'created_at' => now()]);
        return $state;
    }
}

