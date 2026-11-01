<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitation link of a list: whoever opens it (logged in) becomes a member.
 */
#[Fillable(['task_list_id', 'created_by', 'token', 'expires_at'])]
class ListInvite extends Model
{
    public const DAYS = 7;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function taskList(): BelongsTo
    {
        return $this->belongsTo(TaskList::class);
    }
}
