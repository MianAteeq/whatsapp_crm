<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::routes([
    'middleware' => ['auth:sanctum'],
]);

Broadcast::channel('chat.{id}', function ($user, $id) {

    logger([
        'USER_ID' => $user?->id,
        'CHANNEL_ID' => $id,
    ]);

    return true;
});

// Social Media Planner Unified Inbox Channels
Broadcast::channel('social-inbox.{tenantId}', function ($user, $tenantId) {
    return (int) $user?->tenant_id === (int) $tenantId;
});

Broadcast::channel('tenant.{tenantId}', function ($user, $tenantId) {
    return (int) $user?->tenant_id === (int) $tenantId;
});