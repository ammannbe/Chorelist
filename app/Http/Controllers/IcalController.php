<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Support\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Calendar subscription of the user's tasks (read only). Calendar apps often ignore tasks
 * (VTODO) in subscriptions, so every occurrence is an all-day event from start to due date.
 */
class IcalController extends Controller
{
    private const DAYS_AHEAD = 180;

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['ical_token' => Str::random(48)])->save();

        return response()->json(['url' => self::url($user)]);
    }

    public function destroy(Request $request): Response
    {
        $request->user()->forceFill(['ical_token' => null])->save();

        return response()->noContent();
    }

    public static function url(User $user): ?string
    {
        return $user->ical_token ? route('ical.show', self::token($user)) : null;
    }

    /**
     * The URL token is derived from the stored secret with the app key, so the database alone does
     * not reveal the calendars.
     */
    private static function token(User $user): string
    {
        return $user->id.'-'.substr(hash_hmac('sha256', "ical:{$user->id}:{$user->ical_token}", config('app.key')), 0, 40);
    }

    public function show(string $token): Response
    {
        $user = User::whereNotNull('ical_token')->find((int) strtok($token, '-'));
        abort_unless($user !== null && hash_equals(self::token($user), $token), 404);
        $today = Schedule::today($user->timezone);
        $until = Schedule::addDays($today, self::DAYS_AHEAD);

        $tasks = Task::whereIn('task_list_id', $user->accessibleListIds())
            ->whereHas('taskList')
            ->where('archived', false)
            ->where('mode', '!=', 'on_demand')
            ->with(['completions', 'taskList'])
            ->get();

        $events = [];
        foreach ($tasks as $task) {
            [$plain, $completions] = Schedule::fromModel($task);
            $state = Schedule::state($plain, $completions, $today);
            if ($state['start'] === null) {
                continue;
            }
            $events[] = $this->event($task, $state['start'], $state['due']);
            foreach (Schedule::occurrences($plain, Schedule::addDays($state['start'], 1), $until) as $start) {
                if ($plain['paused_until'] === null || $start >= $plain['paused_until']) {
                    $events[] = $this->event($task, $start, Schedule::addDays($start, $task->window_days));
                }
            }
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Chorelist//'.config('app.name').'//DE',
            'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:'.$this->escape(config('app.name')),
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            ...array_merge(...$events ?: [[]]),
            'END:VCALENDAR',
        ];

        return response(implode("\r\n", array_map($this->fold(...), $lines))."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="chorelist.ics"',
        ]);
    }

    /**
     * @return list<string>
     */
    private function event(Task $task, string $start, string $due): array
    {
        return [
            'BEGIN:VEVENT',
            "UID:{$task->id}-{$start}@".parse_url(config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.gmdate('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:'.str_replace('-', '', $start),
            // DTEND of all-day events is exclusive.
            'DTEND;VALUE=DATE:'.str_replace('-', '', Schedule::addDays($due, 1)),
            'SUMMARY:'.$this->escape(trim(($task->icon ? $task->icon.' ' : '').$task->title)),
            'DESCRIPTION:'.$this->escape(trim($task->taskList->name."\n".($task->notes ?? ''))),
            'URL:'.url("/#/task/{$task->id}"),
            'TRANSP:TRANSPARENT',
            'END:VEVENT',
        ];
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $text);
    }

    /** Lines longer than 75 octets are folded (RFC 5545 3.1). */
    private function fold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut)."\r\n ";
            $line = substr($line, $cut);
        }

        return $out.$line;
    }
}
