<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title', 'notes', 'icon', 'category', 'points', 'mode', 'rrule', 'starts_on', 'window_days', 'ends_on',
    'reminders', 'remind_after_days', 'paused_until', 'archived', 'checklist',
])]
class Task extends Model
{
    use HasFactory, Syncable;

    /** Fields shown in the history when changed (activities). */
    public const TRACKED = [
        'title', 'notes', 'icon', 'category', 'points', 'mode', 'rrule', 'starts_on', 'window_days', 'ends_on',
        'reminders', 'remind_after_days', 'paused_until', 'archived',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'window_days' => 'integer',
            'remind_after_days' => 'integer',
            'starts_on' => DateOnly::class,
            'ends_on' => DateOnly::class,
            'paused_until' => DateOnly::class,
            'archived' => 'boolean',
            'reminders' => 'array',
            'checklist' => 'array',
        ];
    }

    public function taskList(): BelongsTo
    {
        return $this->belongsTo(TaskList::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(Completion::class);
    }
}
