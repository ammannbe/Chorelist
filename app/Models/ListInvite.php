<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Invitation of a list: a link for whoever opens it, or a personal one for an existing user the
 * owner shared the list with. Joining needs a confirmation. Only the hash of the token is stored.
 */
#[Fillable(['task_list_id', 'created_by', 'user_id', 'token', 'expires_at'])]
class ListInvite extends Model
{
    public const DAYS = 7;

    /** Personal invitations stay valid longer, the invited user may not open the app every day. */
    public const PERSONAL_DAYS = 30;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return array{0: self, 1: string} the invitation and its token (only known now)
     */
    public static function issue(TaskList $list, User $creator, ?User $for = null): array
    {
        $token = Str::random(40);
        if ($for !== null) {
            // One open personal invitation per user and list.
            self::where('task_list_id', $list->id)->where('user_id', $for->id)->delete();
        }
        $invite = self::create([
            'task_list_id' => $list->id,
            'created_by' => $creator->id,
            'user_id' => $for?->id,
            'token' => self::hash($token),
            'expires_at' => now()->addDays($for ? self::PERSONAL_DAYS : self::DAYS),
        ]);

        return [$invite, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return self::where('token', self::hash($token))->where('expires_at', '>', now())->first();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function taskList(): BelongsTo
    {
        return $this->belongsTo(TaskList::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
