<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('chat.{id}', function ($user, $id) {

    logger([
        'USER_ID' => $user?->id,
        'CHANNEL_ID' => $id,
    ]);

    return true;

    // Secure version:
    // return (int) $user->id === (int) $id;
});