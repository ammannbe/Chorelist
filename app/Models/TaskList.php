<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'icon', 'ranking_enabled', 'ranking_period'])]
class TaskList extends Model
{
    use HasFactory, Syncable;

    public const RANKING_PERIODS = ['week', 'month', 'all'];

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

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    public function hasMember(User $user): bool
    {
        return $this->memberships()->where('user_id', $user->id)->exists();
    }

    /**
     * Add a user, or restore a revoked membership.
     */
    public function addMember(User $user): Membership
    {
        $membership = Membership::withTrashed()->firstOrNew([
            'task_list_id' => $this->id,
            'user_id' => $user->id,
        ]);

        if ($membership->trashed()) {
            $membership->restore();
        } elseif (! $membership->exists) {
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

    public function removeMember(User $user): void
    {
        $this->memberships()->where('user_id', $user->id)->first()?->delete();
        $this->touchForMembers();
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
