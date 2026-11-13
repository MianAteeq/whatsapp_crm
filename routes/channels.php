<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::routes([
    'middleware' => ['auth:sanctum'],
]);

Broadcast::channel('tenant.{id}.inbox', function ($user, $id) {
    return (string) $user->tenant_id === (string) $id && $user->hasPermission('inbox.view');
});

Broadcast::channel('tenant.{id}.campaigns', function ($user, $id) {
    return (string) $user->tenant_id === (string) $id && $user->hasPermission('campaigns.view');
});

Broadcast::channel('chat.{id}', function ($user, $id) {
    return $user->hasPermission('inbox.view')
        && \App\Models\Conversation::where('tenant_id', $user->tenant_id)->whereKey($id)->exists();
});
