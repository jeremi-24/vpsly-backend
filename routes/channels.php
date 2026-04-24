<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('deployment.{id}', function ($user, $id) {
    $deployment = \App\Models\Deployment::find($id);
    $isOwner = $deployment && $deployment->application->user_id === $user->id;
    
    \Illuminate\Support\Facades\Log::info("[BroadcastAuth] User {$user->id} attempting to join channel for Deployment {$id}. Owner: " . ($deployment ? $deployment->application->user_id : 'none') . ". Result: " . ($isOwner ? 'ALLOWED' : 'DENIED'));
    
    return $isOwner;
});

