<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Invitation to a household by email. Only the user with that (verified) address can accept it,
 * by the link in the mail or on the households page. Only the hash of the token is stored.
 */
#[Fillable(['household_id', 'email', 'token_hash', 'role', 'invited_by', 'expires_at', 'accepted_at'])]
class HouseholdInvitation extends Model
{
    public const DAYS = 30;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return array{0: self, 1: string} the invitation and its token (only known now)
     */
    public static function issue(Household $household, User $inviter, string $email, string $role): array
    {
        $email = Str::lower($email);
        // One open invitation per address and household.
        self::where('household_id', $household->id)->where('email', $email)->whereNull('accepted_at')->delete();

        $token = Str::random(40);
        $invitation = self::create([
            'household_id' => $household->id,
            'email' => $email,
            'token_hash' => self::hash($token),
            'role' => $role,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(self::DAYS),
        ]);

        return [$invitation, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return self::open()->where('token_hash', self::hash($token))->first();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isFor(User $user): bool
    {
        return $user->hasVerifiedEmail() && Str::lower($user->email) === $this->email;
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function accept(User $user): void
    {
        $this->household->addUser($user, $this->role);
        $this->update(['accepted_at' => now()]);
    }
}
