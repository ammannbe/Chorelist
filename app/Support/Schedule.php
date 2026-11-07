<?php

namespace App\Support;

use App\Models\Completion;
use App\Models\Task;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RRule\RRule;

/**
 * When a task is due. Same rules as resources/js/schedule.js (tests/fixtures/schedule.json
 * is run by both test suites).
 *
 * Modes:
 * - fixed: calendar, RFC 5545 RRULE from the start date (DTSTART) until ends_on.
 * - after_completion: FREQ/INTERVAL of the RRULE after the last completion (or skip).
 * - once: one occurrence on the start date.
 * - dates: one occurrence on each of the given dates (e.g. waste collection), finished after the last.
 * - on_demand: no due date, done whenever needed.
 *
 * An occurrence starts on its date and is due window_days later (or on the date it was
 * postponed to). Only one occurrence is open at a time: the latest one that started and is not
 * done or skipped yet, otherwise the next one. Earlier open ones count as missed.
 */
class Schedule
{
    public const MODES = ['fixed', 'after_completion', 'once', 'dates', 'on_demand'];

    /** Dates of a task in the mode `dates`. */
    public const MAX_DATES = 400;

    public const RULE_PARTS = ['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'BYSETPOS', 'WKST'];

    /** Occurrences checked after the last started one (e.g. several completed in advance). */
    private const MAX_AHEAD = 60;

    /**
     * State of a task on a day.
     *
     * @param  array{mode: string, rrule: ?string, starts_on: string, window_days?: int, ends_on?: ?string, paused_until?: ?string, dates?: ?list<string>}  $task
     * @param  list<array{occurrence: ?string, done_on: string, type: string, postponed_to?: ?string}>  $completions  of this task
     * @return array{occurrence: ?string, start: ?string, due: ?string, status: string, last_done: ?string}
     */
    public static function state(array $task, array $completions, string $today): array
    {
        $completions = array_values(array_filter($completions, fn ($c) => $c['type'] !== 'undone'));
        $lastDone = self::lastDone($completions);
        $base = ['occurrence' => null, 'start' => null, 'due' => null, 'last_done' => $lastDone];

        if ($task['mode'] === 'on_demand') {
            return [...$base, 'status' => 'on_demand'];
        }

        $start = match ($task['mode']) {
            'once' => self::isResolved($completions, $task['starts_on']) ? null : $task['starts_on'],
            'after_completion' => self::afterCompletionStart($task, $completions),
            'dates' => self::datesStart($task, $completions, $today),
            default => self::fixedStart($task, $completions, $today),
        };

        if ($start === null) {
            return [...$base, 'status' => 'finished'];
        }

        $postponed = self::find($completions, $start, 'postponed');
        $due = $postponed['postponed_to'] ?? self::addDays($start, (int) ($task['window_days'] ?? 0));

        return [
            ...$base,
            'occurrence' => $start,
            'start' => $start,
            'due' => $due,
            'status' => self::status($start, $due, $today),
        ];
    }

    /**
     * Occurrences of a fixed (once, dates) task between two dates (inclusive), e.g. for the calendar feed
     * and the missed ones in the history.
     *
     * @return list<string>
     */
    public static function occurrences(array $task, string $from, string $to): array
    {
        if ($task['mode'] === 'once') {
            return $task['starts_on'] >= $from && $task['starts_on'] <= $to ? [$task['starts_on']] : [];
        }
        if ($task['mode'] === 'dates') {
            return array_values(array_filter(self::dates($task), fn (string $date) => $date >= $from && $date <= $to));
        }
        if ($task['mode'] !== 'fixed') {
            return [];
        }

        return array_map(
            fn (\DateTimeInterface $date) => $date->format('Y-m-d'),
            self::rule($task)->getOccurrencesBetween(self::date($from), self::date($to)),
        );
    }

    /**
     * Occurrences of a fixed (or dates) task that started before the open one and were neither
     * done nor skipped (from a date on, paused ones not counted).
     *
     * @return list<string>
     */
    public static function missed(array $task, array $completions, string $from, string $today): array
    {
        if (! in_array($task['mode'], ['fixed', 'dates'], true)) {
            return [];
        }
        $completions = array_values(array_filter($completions, fn ($c) => $c['type'] !== 'undone'));
        $open = self::state($task, $completions, $today)['start'] ?? self::addDays($today, 1);
        $until = min($open, $today);

        return array_values(array_filter(
            self::occurrences($task, $from, $until),
            fn (string $date) => $date < $open && ! self::isResolved($completions, $date) && ! self::isPaused($task, $date),
        ));
    }

    /**
     * Validates an RRULE (without DTSTART) of the parts this app supports.
     */
    public static function validRule(?string $rule): bool
    {
        if ($rule === null || $rule === '') {
            return false;
        }
        try {
            $parts = self::parseRule($rule);
            if (array_diff(array_keys($parts), self::RULE_PARTS) || ! isset($parts['FREQ'])) {
                return false;
            }
            new RRule([...$parts, 'DTSTART' => self::date('2026-01-01')]);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Today's date in a time zone (the user's, reminders are sent on their day).
     */
    public static function today(?string $timezone = null): string
    {
        return CarbonImmutable::now($timezone ?: config('app.timezone'))->format('Y-m-d');
    }

    /**
     * Dates of the mode `dates`: valid Y-m-d strings, sorted, each once.
     *
     * @return list<string>
     */
    public static function normalizeDates(mixed $dates): array
    {
        $dates = array_filter((array) $dates, fn ($date) => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date));
        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }

    public static function addDays(string $date, int $days): string
    {
        return self::date($date)->modify(($days >= 0 ? '+' : '').$days.' days')->format('Y-m-d');
    }

    /**
     * Task model (with its completions loaded or given) as plain arrays.
     *
     * @param  Collection<int, Completion>|null  $completions
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    public static function fromModel(Task $task, ?Collection $completions = null): array
    {
        $completions ??= $task->completions;

        return [[
            'mode' => $task->mode,
            'rrule' => $task->rrule,
            'starts_on' => $task->starts_on->format('Y-m-d'),
            'window_days' => $task->window_days,
            'ends_on' => $task->ends_on?->format('Y-m-d'),
            'paused_until' => $task->paused_until?->format('Y-m-d'),
            'dates' => $task->dates,
        ], $completions->map(fn (Completion $c) => [
            'occurrence' => $c->occurrence?->format('Y-m-d'),
            'done_on' => $c->done_on->format('Y-m-d'),
            'type' => $c->type,
            'postponed_to' => $c->postponed_to?->format('Y-m-d'),
        ])->values()->all()];
    }

    // ---------------------------------------------------------------- internals

    private static function fixedStart(array $task, array $completions, string $today): ?string
    {
        $rule = self::rule($task);
        // Latest occurrence that started (today included).
        $last = $rule->getOccurrencesBefore(self::date($today), true, 1)[0] ?? null;
        $last = $last?->format('Y-m-d');
        if ($last !== null && ! self::isPaused($task, $last) && ! self::isResolved($completions, $last)) {
            return $last;
        }

        $after = $last ?? self::addDays($task['starts_on'], -1);
        foreach ($rule->getOccurrencesAfter(self::date($after), false, self::MAX_AHEAD) as $date) {
            $date = $date->format('Y-m-d');
            if (! self::isPaused($task, $date) && ! self::isResolved($completions, $date)) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Same as fixedStart() with the given dates instead of a rule.
     */
    private static function datesStart(array $task, array $completions, string $today): ?string
    {
        $dates = self::dates($task);
        // Latest date that started (today included).
        $started = array_filter($dates, fn (string $date) => $date <= $today);
        $last = $started ? end($started) : null;
        if ($last !== null && ! self::isPaused($task, $last) && ! self::isResolved($completions, $last)) {
            return $last;
        }

        foreach ($dates as $date) {
            if (($last === null || $date > $last) && ! self::isPaused($task, $date) && ! self::isResolved($completions, $date)) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function dates(array $task): array
    {
        return self::normalizeDates($task['dates'] ?? []);
    }

    private static function afterCompletionStart(array $task, array $completions): ?string
    {
        $resolved = array_filter($completions, fn ($c) => in_array($c['type'], ['done', 'skipped'], true) && $c['occurrence'] !== null);
        $last = $resolved ? max(array_column($resolved, 'done_on')) : null;
        $start = $last === null ? $task['starts_on'] : self::addInterval($last, $task['rrule']);
        if (! empty($task['paused_until']) && $start < $task['paused_until']) {
            $start = $task['paused_until'];
        }

        return ! empty($task['ends_on']) && $start > $task['ends_on'] ? null : $start;
    }

    private static function addInterval(string $date, ?string $rule): string
    {
        $parts = self::parseRule($rule ?? 'FREQ=WEEKLY');
        $interval = max(1, (int) ($parts['INTERVAL'] ?? 1));
        $unit = ['DAILY' => 'days', 'WEEKLY' => 'weeks', 'MONTHLY' => 'months', 'YEARLY' => 'years'][$parts['FREQ'] ?? 'WEEKLY'] ?? 'weeks';
        $start = self::date($date);
        $next = $start->modify("+{$interval} {$unit}");
        // Same as JS: month ends do not overflow (31 Jan + 1 month = 28/29 Feb).
        if (in_array($unit, ['months', 'years'], true) && (int) $next->format('d') !== (int) $start->format('d')) {
            $next = $next->modify('last day of previous month');
        }

        return $next->format('Y-m-d');
    }

    private static function status(string $start, string $due, string $today): string
    {
        return match (true) {
            $today < $start => 'upcoming',
            $today > $due => 'overdue',
            $today === $due => 'due',
            default => 'open',
        };
    }

    private static function lastDone(array $completions): ?string
    {
        $done = array_filter($completions, fn ($c) => in_array($c['type'], ['done', 'extra'], true));

        return $done ? max(array_column($done, 'done_on')) : null;
    }

    private static function isResolved(array $completions, string $occurrence): bool
    {
        return self::find($completions, $occurrence, 'done') !== null || self::find($completions, $occurrence, 'skipped') !== null;
    }

    private static function find(array $completions, string $occurrence, string $type): ?array
    {
        foreach ($completions as $completion) {
            if ($completion['occurrence'] === $occurrence && $completion['type'] === $type) {
                return $completion;
            }
        }

        return null;
    }

    private static function isPaused(array $task, string $date): bool
    {
        return ! empty($task['paused_until']) && $date < $task['paused_until'];
    }

    private static function rule(array $task): RRule
    {
        $parts = self::parseRule($task['rrule'] ?? 'FREQ=DAILY');
        $parts['DTSTART'] = self::date($task['starts_on']);
        if (! empty($task['ends_on'])) {
            $parts['UNTIL'] = self::date($task['ends_on']);
        }

        return new RRule($parts);
    }

    /**
     * @return array<string, string>
     */
    private static function parseRule(?string $rule): array
    {
        $parts = [];
        foreach (array_filter(explode(';', preg_replace('/^RRULE:/i', '', trim((string) $rule)))) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[strtoupper(trim($key))] = strtoupper(trim($value));
        }

        return $parts;
    }

    private static function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC'));
    }
}
