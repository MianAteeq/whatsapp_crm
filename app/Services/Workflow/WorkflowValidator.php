<?php

declare(strict_types=1);

namespace App\Services\Workflow;

final class WorkflowValidator
{
    public function validate(array $nodes, array $connections): array
    {
        $errors = [];
        $warnings = [];
        $ids = array_values(array_filter(array_map(fn ($node) => $node['id'] ?? null, $nodes)));
        $validTriggers = [
            'trigger', 'incoming_message', 'keyword', 'contact_created', 'contact_updated', 
            'birthday', 'schedule', 'lead_form_submitted', 'form_submitted', 'click_to_whatsapp', 
            'ad_click', 'button_clicked', 'list_selected', 'template_sent', 'template_delivered', 
            'template_read', 'template_failed', 'customer_replied', 'appointment_created', 
            'appointment_updated', 'opportunity_created', 'opportunity_stage_changed', 
            'tag_added', 'tag_removed', 'tag_changed', 'review_received', 'incoming_webhook', 
            'stale_opportunity', 'staff_reply', 'tracked_link_clicked'
        ];
        $triggers = array_filter($nodes, fn ($node) => in_array($node['type'] ?? '', $validTriggers, true));

        if ($nodes === []) $errors[] = ['code' => 'empty_workflow', 'message' => 'Add at least one workflow node.'];
        if (count($triggers) !== 1) $errors[] = ['code' => 'trigger_count', 'message' => 'A workflow must contain exactly one trigger.'];
        if (count($ids) !== count(array_unique($ids))) $errors[] = ['code' => 'duplicate_node_id', 'message' => 'Node identifiers must be unique.'];

        foreach ($connections as $index => $connection) {
            $source = $connection['source_node_id'] ?? $connection['source'] ?? null;
            $target = $connection['target_node_id'] ?? $connection['target'] ?? null;
            if (!in_array($source, $ids, true) || !in_array($target, $ids, true)) {
                $errors[] = ['code' => 'invalid_connection', 'message' => "Connection #".($index + 1).' references a missing node.'];
            }
            if ($source === $target) $errors[] = ['code' => 'self_cycle', 'message' => "Node {$source} cannot connect to itself."];
        }

        $connected = [];
        foreach ($connections as $connection) {
            $connected[] = $connection['source_node_id'] ?? $connection['source'] ?? null;
            $connected[] = $connection['target_node_id'] ?? $connection['target'] ?? null;
        }
        foreach ($nodes as $node) {
            if (count($nodes) > 1 && !in_array($node['id'] ?? null, $connected, true)) {
                $warnings[] = ['code' => 'disconnected_node', 'node_id' => $node['id'] ?? null, 'message' => 'This node is disconnected.'];
            }
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
    }
}
