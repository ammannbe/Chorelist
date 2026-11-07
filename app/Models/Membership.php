<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('task_list_user')]
#[Fillable(['task_list_id', 'user_id', 'direct', 'position', 'muted'])]
class Membership extends Model
{
    use Syncable;

    protected function casts(): array
    {
        return [
            'position' => 'float',
            'muted' => 'boolean',
            // False: access only through a household (TaskList::syncHouseholdMembers).
            'direct' => 'boolean',
        ];
    }

    public function taskList(): BelongsTo
    {
        return $this->belongsTo(TaskList::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
