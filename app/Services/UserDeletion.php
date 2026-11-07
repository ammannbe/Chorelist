<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Membership;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an account (the account page, and the webhook user.deleted of the AccountManager,
 * docs/ENTITLEMENTS-API.md 5.2): own lists are deleted for all members, shared ones are left.
 * Completions in other lists stay (without the user), so their ranking keeps its history.
 * Households are left: another member takes over as admin, empty households are deleted
 * (mirrored ones: the AccountManager decides).
 */
class UserDeletion
{
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->households()->get()->each(fn (Household $household) => $household->removeUser($user, handOver: ! $household->isMirrored()));
            TaskList::where('owner_id', $user->id)->get()->each(function (TaskList $list) {
                $list->delete();
                $list->touchForMembers();
            });
            $user->memberships()->get()->each(fn (Membership $membership) => $membership->taskList?->removeMember($user));

            // Other devices are signed out (database session driver).
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();
            }

            $user->delete();
        });
    }
}
