<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * History entry ("who set up or changed what"), written by the server, pulled by the members.
 */
#[Fillable(['task_list_id', 'task_id', 'user_id', 'action', 'data'])]
class Activity extends Model
{
    use Syncable;

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function record(TaskList|string $list, ?User $user, string $action, array $data = [], ?string $taskId = null): self
    {
        return self::create([
            'task_list_id' => $list instanceof TaskList ? $list->id : $list,
            'task_id' => $taskId,
            'user_id' => $user?->id,
            'action' => $action,
            'data' => $data ?: null,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
