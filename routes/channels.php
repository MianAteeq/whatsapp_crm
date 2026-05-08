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