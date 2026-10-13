<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Broadcast delivery events to authenticated users
Broadcast::channel('deliveries', function ($user) {
    return $user && in_array($user->role, ['ADMIN', 'RECEPTIONIST']);
});