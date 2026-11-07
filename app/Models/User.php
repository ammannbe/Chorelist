<?php

namespace App\Models;

use App\Http\Controllers\PreferenceController;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use LogicException;
use NotificationChannels\WebPush\HasPushSubscriptions;

#[Fillable(['name', 'email', 'password', 'locale', 'timezone'])]
#[Hidden(['password', 'remember_token', 'ical_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable, PasskeyAuthenticatable;

    protected static function booted(): void
    {
        // A linked account logs in through the OIDC provider only; link and unlink through App\Auth\OidcAccount.
        static::saving(function (User $user): void {
            if ($user->isLinkedToOidc() && $user->password !== null && $user->isDirty(['password', 'oidc_sub'])) {
                throw new LogicException('An account linked to the OIDC login cannot have a password.');
            }
        });

        // A stored entitlements snapshot belongs to the subject it was synced for (versions count per subject).
        static::updated(function (User $user): void {
            if ($user->wasChanged('oidc_sub')) {
                $user->entitlement()->delete();
                $user->unsetRelation('entitlement');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    /**
     * Mails (verification, password reset, invitations, reminders) use the user's language.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Logs in through the OpenID Connect provider only (see App\Auth\OidcAccount).
     */
    public function isLinkedToOidc(): bool
    {
        return $this->oidc_sub !== null;
    }

    /**
     * Name shown to the other members.
     */
    public function displayName(): string
    {
        return $this->name ?: $this->email;
    }

    /**
     * @return array<string, mixed>
     */
    public function preferences(): array
    {
        $preferences = [...PreferenceController::DEFAULTS, ...($this->preferences ?? [])];
        $preferences['notifications'] = [...PreferenceController::DEFAULTS['notifications'], ...($this->preferences['notifications'] ?? [])];

        return $preferences;
    }

    /**
     * Channels the user chose for a kind of notification (push, mail), see PreferenceController::NOTIFICATIONS.
     *
     * @return list<string>
     */
    public function channelsFor(string $kind): array
    {
        return array_values(array_intersect($this->preferences()['notifications'][$kind] ?? [], ['push', 'mail']));
    }

    public function isAbsentOn(string $date): bool
    {
        return $this->absences()->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->exists();
    }

    /**
     * Last entitlements snapshot of the AccountManager (linked users only, App\Entitlements\Entitlements).
     *
     * @return HasOne<UserEntitlement, $this>
     */
    public function entitlement(): HasOne
    {
        return $this->hasOne(UserEntitlement::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class)->withPivot('role', 'joined_at');
    }

    /**
     * Open household invitations for the user's address (only once it is verified).
     */
    public function householdInvitations(): Builder
    {
        return HouseholdInvitation::open()
            ->where('email', mb_strtolower($this->email))
            ->unless($this->hasVerifiedEmail(), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->whereNotIn('household_id', $this->households()->select('households.id'));
    }

    /**
     * IDs of all non-deleted lists the user is currently a member of.
     */
    public function accessibleListIds(): Builder
    {
        return TaskList::query()
            ->select('task_lists.id')
            ->join('task_list_user', 'task_list_user.task_list_id', '=', 'task_lists.id')
            ->where('task_list_user.user_id', $this->id)
            ->whereNull('task_list_user.deleted_at');
    }

    /**
     * IDs of the users sharing a list with this user (the user included).
     */
    public function coMemberIds(): Builder
    {
        return Membership::query()
            ->select('user_id')
            ->whereIn('task_list_id', $this->accessibleListIds());
    }
}
