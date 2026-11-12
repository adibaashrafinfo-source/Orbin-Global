<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

class NotificationPolicy
{
    public function update(User $actor, Notification $notification): bool
    {
        return $notification->user_id === $actor->id || ($notification->user_id === null && $notification->role === $actor->role);
    }
}
