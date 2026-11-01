<?php

namespace App\Support;

use App\Models\Membership;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Notifications\Notification;

class Notify
{
    /**
     * Notifies the other members of a list who want this kind of notification and did not mute the list.
     */
    public static function members(TaskList $list, User $actor, string $kind, Notification $notification): void
    {
        // Called from sync transactions: only queued once the change is saved.
        $notification->afterCommit();

        $list->memberships()
            ->where('user_id', '!=', $actor->id)
            ->where('muted', false)
            ->with('user')
            ->get()
            ->map(fn (Membership $membership) => $membership->user)
            ->filter(fn (User $user) => $user->channelsFor($kind) !== [])
            ->each(fn (User $user) => $user->notify($notification));
    }
}
