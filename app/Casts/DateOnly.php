<?php

namespace App\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Plain dates (YYYY-MM-DD). Synced models store timestamps with milliseconds (Syncable), the
 * default date cast would store dates with a time too.
 *
 * @implements CastsAttributes<Carbon|null, string|DateTimeInterface|null>
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::createFromFormat('Y-m-d', substr((string) $value, 0, 10))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
