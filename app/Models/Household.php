<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Household (e.g. "Familie Muster"): lists shared with it are accessible to all its members,
 * resolved through the membership. Removing a member or deleting the household only removes
 * access, the lists stay with their owners.
 *
 * Access is kept as list memberships (Membership::$direct false), so the sync, notifications and
 * reminders work as for individual members: every change of the members or shares calls
 * TaskList::syncHouseholdMembers().
 */
#[Fillable(['name', 'created_by', 'konto_household_uuid'])]
class Household extends Model
{
    public const ADMIN = 'admin';

    public const MEMBER = 'member';

    public const ROLES = [self::ADMIN, self::MEMBER];

    protected static function booted(): void
    {
        static::creating(function (Household $household) {
            $household->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'konto_version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Households are managed in the app (HOUSEHOLDS_DRIVER=local), not mirrored from the AccountManager.
     */
    public static function managedLocally(): bool
    {
        return config('households.driver') === 'local';
    }

    /**
     * Mirrored from the AccountManager (HOUSEHOLDS_DRIVER=konto): name and members come from there.
     * After switching back to "local" mirrored households are ordinary local ones.
     */
    public function isMirrored(): bool
    {
        return $this->konto_household_uuid !== null && ! self::managedLocally();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'joined_at');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(HouseholdInvitation::class);
    }

    /**
     * Members of the mirrored household without a user here yet (attached on their first OIDC login).
     */
    public function pendingMembers(): HasMany
    {
        return $this->hasMany(HouseholdPendingMember::class);
    }

    public function taskLists(): BelongsToMany
    {
        return $this->belongsToMany(TaskList::class, 'task_list_household')->withPivot('shared_by')->withTimestamps();
    }

    public function roleOf(User $user): ?string
    {
        return $this->users()->whereKey($user->id)->first()?->pivot->role;
    }

    public function hasUser(User $user): bool
    {
        return $this->roleOf($user) !== null;
    }

    public function isAdmin(User $user): bool
    {
        return $this->roleOf($user) === self::ADMIN;
    }

    /**
     * The only admin cannot leave or lose the role (at least one admin per household).
     */
    public function isLastAdmin(User $user): bool
    {
        return $this->isAdmin($user) && $this->users()->wherePivot('role', self::ADMIN)->count() === 1;
    }

    public function addUser(User $user, string $role = self::MEMBER): void
    {
        DB::transaction(function () use ($user, $role) {
            if ($this->hasUser($user)) {
                return;
            }
            $this->users()->attach($user, ['role' => $role, 'joined_at' => now()]);
            $this->syncLists();
        });
    }

    public function setRole(User $user, string $role): void
    {
        $this->users()->updateExistingPivot($user->id, ['role' => $role]);
    }

    /**
     * The user loses access to the lists shared with the household, and their own lists are not
     * shared with it anymore. With $handOver (account deleted), the longest member becomes admin
     * if the user was the last one, and an empty household is deleted.
     */
    public function removeUser(User $user, bool $handOver = false): void
    {
        DB::transaction(function () use ($user, $handOver) {
            $lastAdmin = $this->isLastAdmin($user);
            $this->detachUsers([$user->id]);
            $this->syncLists();

            if (! $handOver) {
                return;
            }
            if (! $this->users()->exists()) {
                $this->deleteWithShares();
            } elseif ($lastAdmin) {
                $next = $this->users()->orderByPivot('joined_at')->orderByPivot('id')->first();
                $this->setRole($next, self::ADMIN);
            }
        });
    }

    /**
     * Removes the users without syncing the shared lists (the caller does): their own lists are no
     * longer shared with the household. Returns these lists.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, TaskList>
     */
    public function detachUsers(array $userIds): Collection
    {
        $this->users()->detach($userIds);
        $own = $this->taskLists()->withTrashed()->whereIn('owner_id', $userIds)->get();
        $this->taskLists()->detach($own->modelKeys());
        $own->each->syncHouseholdMembers();

        return $own;
    }

    /**
     * The lists stay with their owners, only the members lose access.
     */
    public function deleteWithShares(): void
    {
        DB::transaction(function () {
            $lists = $this->taskLists()->get();
            $this->taskLists()->detach();
            $this->delete();
            $lists->each->syncHouseholdMembers();
        });
    }

    /**
     * Grants or revokes access to the shared lists after the members changed.
     */
    public function syncLists(): void
    {
        $this->taskLists()->get()->each->syncHouseholdMembers();
    }

    /**
     * Name and members are part of the list documents.
     */
    public function touchLists(): void
    {
        $this->taskLists()->get()->each->touchForMembers();
    }
}
