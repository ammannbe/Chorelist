<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Holidays: no reminders, and the ranking compares the points per day present.
 */
#[Fillable(['starts_on', 'ends_on', 'note'])]
class Absence extends Model
{
    use HasFactory, Syncable;

    protected function casts(): array
    {
        return [
            'starts_on' => DateOnly::class,
            'ends_on' => DateOnly::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
