<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ramsey\Uuid\Uuid;

#[Fillable(['occurrence', 'done_on', 'type', 'postponed_to', 'points', 'note'])]
class Completion extends Model
{
    use HasFactory, Syncable;

    /**
     * done: the occurrence (or an on demand task) was done; extra: done once more, outside the
     * schedule; skipped: the occurrence is left out (no points); postponed: the occurrence is due
     * later (postponed_to); undone: taken back (instead of deleting, so the same occurrence id can
     * be completed again).
     */
    public const TYPES = ['done', 'extra', 'skipped', 'postponed', 'undone'];

    /** Types that give points. */
    public const SCORED = ['done', 'extra'];

    protected function casts(): array
    {
        return [
            'occurrence' => DateOnly::class,
            'done_on' => DateOnly::class,
            'postponed_to' => DateOnly::class,
            'points' => 'integer',
        ];
    }

    /**
     * Id of the completion of an occurrence, same as occurrenceId() in resources/js/schedule.js.
     */
    public static function occurrenceId(string $taskId, string $occurrence): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "{$taskId}:{$occurrence}")->toString();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function taskList(): BelongsTo
    {
        return $this->belongsTo(TaskList::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_id');
    }
}
