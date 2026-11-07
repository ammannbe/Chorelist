<?php

namespace App\Models;

use App\Households\KontoHouseholds;
use App\Jobs\ReportKontoShares;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'icon', 'ranking_enabled', 'ranking_period'])]
class TaskList extends Model
{
    use HasFactory, Syncable;

    public const RANKING_PERIODS = ['week', 'month', 'all'];

    protected static function booted(): void
    {
        // The AccountManager shows the names of the lists shared with a household (HOUSEHOLDS_DRIVER=konto).
        static::updated(function (TaskList $list) {
            if ($list->wasChanged(['name', 'icon'])) {
                $list->reportKontoShares();
            }
        });
        static::deleted(fn (TaskList $list) => $list->reportKontoShares());
        static::restored(fn (TaskList $list) => $list->reportKontoShares());
    }

    public function reportKontoShares(): void
    {
        if (KontoHouseholds::enabled()) {
            ReportKontoShares::dispatchFor($this->households()->whereNotNull('konto_household_uuid')->get());
        }
    }

    protected function casts(): array
    {
        return [
            'ranking_enabled' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Active memberships (the owner is a member too).
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function completions(): HasMany
    {
        return $this->hasMany(Completion::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Households the list is shared with: all their members have access.
     */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'task_list_household')->withPivot('shared_by')->withTimestamps();
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    public function hasMember(User $user): bool
    {
        return $this->memberships()->where('user_id', $user->id)->exists();
    }

    /**
     * The user is in a household the list is shared with.
     */
    public function hasHouseholdAccess(User $user): bool
    {
        return $this->households()->whereHas('users', fn ($query) => $query->whereKey($user->id))->exists();
    }

    /**
     * Add a user, or restore a revoked membership. $direct: shared with the user themselves
     * (otherwise only through a household).
     */
    public function addMember(User $user, bool $direct = true): Membership
    {
        $membership = Membership::withTrashed()->firstOrNew([
            'task_list_id' => $this->id,
            'user_id' => $user->id,
        ]);

        if ($membership->exists && ! $membership->trashed()) {
            if ($direct && ! $membership->direct) {
                $membership->direct = true;
                $membership->save();
                $this->touchForMembers();
            }

            return $membership;
        }

        $membership->direct = $direct;
        if ($membership->trashed()) {
            $membership->restore();
        } else {
            $membership->save();
        }

        $this->touchForMembers();
        // A new member has to pull everything of the list, and everybody the absences of the others.
        $this->tasks()->touch();
        $this->completions()->touch();
        $this->activities()->touch();
        Absence::whereIn('user_id', $this->memberships()->select('user_id'))->touch();

        return $membership;
    }

    /**
     * Revokes the user's own membership. With access through a household they stay a member
     * (through the household).
     */
    public function removeMember(User $user): void
    {
        $membership = $this->memberships()->where('user_id', $user->id)->first();
        if ($membership === null) {
            return;
        }

        if ($this->hasHouseholdAccess($user)) {
            if ($membership->direct) {
                $membership->update(['direct' => false]);
                $this->touchForMembers();
            }

            return;
        }

        $membership->delete();
        $this->touchForMembers();
    }

    /**
     * Grants the members of the households access and revokes it from former ones (if they are not
     * members on their own). Called whenever the shares or the members of a household change.
     *
     * @return Collection<int, User> users that got access
     */
    public function syncHouseholdMembers(): Collection
    {
        $userIds = DB::table('household_user')
            ->whereIn('household_id', DB::table('task_list_household')->where('task_list_id', $this->id)->select('household_id'))
            ->distinct()
            ->pluck('user_id');

        $granted = User::whereKey($userIds)
            ->whereNotIn('id', $this->memberships()->select('user_id'))
            ->get()
            ->each(fn (User $user) => $this->addMember($user, direct: false));

        $revoked = $this->memberships()->where('direct', false)->whereNotIn('user_id', $userIds)->get();
        if ($revoked->isNotEmpty()) {
            $revoked->each->delete();
            $this->touchForMembers();
        }

        return $granted;
    }

    /**
     * List documents are pulled per membership, so every change of the
     * list (name, members, deletion) has to touch all memberships.
     */
    public function touchForMembers(): void
    {
        $this->memberships()->touch();
    }
}
