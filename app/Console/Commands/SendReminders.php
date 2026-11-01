<?php

namespace App\Console\Commands;

use App\Models\Completion;
use App\Models\Membership;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Notifications\ChoreNotification;
use App\Notifications\TaskReminder;
use App\Notifications\WeeklySummary;
use App\Support\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runs every few minutes: sends due reminders once the user's reminder time has come on their
 * day (their time zone). Every reminder is sent once (reminder_deliveries).
 */
#[Signature('reminders:send {--dry-run : List the reminders without sending them}')]
#[Description('Send due task reminders, overdue notices and the weekly summary')]
class SendReminders extends Command
{
    public function handle(): int
    {
        $sent = 0;

        User::query()
            ->whereNotNull('email_verified_at')
            ->whereHas('memberships')
            ->each(function (User $user) use (&$sent) {
                $sent += $this->remind($user);
            });

        DB::table('reminder_deliveries')->where('created_at', '<', now()->subDays(120))->delete();
        $this->info("Sent {$sent} notification(s).");

        return self::SUCCESS;
    }

    private function remind(User $user): int
    {
        $timezone = $user->timezone ?: config('app.timezone');
        $now = CarbonImmutable::now($timezone);
        if ($now->format('H:i') < ($user->preferences()['reminder_time'] ?? '08:00')) {
            return 0;
        }
        $today = $now->format('Y-m-d');
        if ($user->isAbsentOn($today)) {
            return 0;
        }

        $sent = 0;
        // Deleted lists keep their memberships and tasks, so only lists that still exist.
        $lists = TaskList::whereIn('id', Membership::where('user_id', $user->id)->where('muted', false)->select('task_list_id'))
            ->pluck('id');
        $tasks = Task::whereIn('task_list_id', $lists)
            ->where('archived', false)
            ->with(['completions', 'taskList'])
            ->get();

        foreach ($tasks as $task) {
            foreach ($this->remindersOf($task, $today) as [$key, $notification]) {
                $sent += $this->send($user, $key, $notification);
            }
        }

        if ($now->isMonday()) {
            $sent += $this->send($user, 'w:'.$now->format('o-\WW'), $this->weeklySummary($user, $tasks, $today));
        }

        return $sent;
    }

    /**
     * @return list<array{0: string, 1: TaskReminder}>
     */
    private function remindersOf(Task $task, string $today): array
    {
        [$plain, $completions] = Schedule::fromModel($task);
        $state = Schedule::state($plain, $completions, $today);
        $reminders = [];

        if ($state['status'] === 'on_demand') {
            if ($task->remind_after_days) {
                $since = $state['last_done'] ?? $plain['starts_on'];
                $days = -$this->days($today, $since);
                if ($days >= $task->remind_after_days) {
                    $reminders[] = ["d:{$task->id}:".($state['last_done'] ?? 'never'), new TaskReminder($task, 'on_demand', $state, $days)];
                }
            }

            return $reminders;
        }
        if ($state['due'] === null) {
            return [];
        }

        if ($state['status'] === 'overdue') {
            return [["o:{$task->id}:{$state['occurrence']}", new TaskReminder($task, 'overdue', $state)]];
        }

        $daysLeft = $this->days($today, $state['due']);
        foreach ($task->reminders ?? [] as $offset) {
            if ($offset === $daysLeft) {
                $reminders[] = ["r:{$task->id}:{$state['occurrence']}:{$offset}", new TaskReminder($task, 'before', $state, $offset)];
            }
        }

        return $reminders;
    }

    /**
     * @param  Collection<int, Task>  $tasks
     */
    private function weeklySummary(User $user, Collection $tasks, string $today): WeeklySummary
    {
        $weekEnd = Schedule::addDays($today, 6);
        $due = $tasks->map(function (Task $task) use ($today) {
            [$plain, $completions] = Schedule::fromModel($task);

            return [$task, Schedule::state($plain, $completions, $today)];
        })->filter(fn (array $entry) => $entry[1]['due'] !== null && $entry[1]['start'] <= $weekEnd)
            ->sortBy(fn (array $entry) => $entry[1]['due'])
            ->map(fn (array $entry) => ['title' => $entry[0]->title, 'list' => $entry[0]->taskList->name, 'due' => $entry[1]['due']])
            ->values()->all();

        // Points of last week (Monday to Sunday) in the lists with a ranking.
        [$from, $to] = [Schedule::addDays($today, -7), Schedule::addDays($today, -1)];
        $rankings = $tasks->pluck('taskList')->unique('id')->filter->ranking_enabled
            ->map(fn ($list) => [
                'list' => $list->name,
                'ranking' => Completion::where('task_list_id', $list->id)
                    ->whereIn('type', Completion::SCORED)
                    ->whereBetween('done_on', [$from, $to])
                    ->with('user')
                    ->get()
                    ->groupBy('user_id')
                    ->map(fn ($completions) => ['name' => $completions->first()->user?->displayName() ?? '?', 'points' => (int) $completions->sum('points')])
                    ->sortByDesc('points')->values()->all(),
            ])->filter(fn ($ranking) => $ranking['ranking'] !== [])->values()->all();

        return new WeeklySummary($rankings, $due);
    }

    private function send(User $user, string $key, ChoreNotification $notification): int
    {
        if ($notification->via($user) === []) {
            return 0;
        }
        if ($this->option('dry-run')) {
            $this->line("{$user->email}: {$key}");

            return 1;
        }
        $new = DB::table('reminder_deliveries')->insertOrIgnore(['user_id' => $user->id, 'key' => $key, 'created_at' => now()]);
        if ($new) {
            $user->notify($notification);
        }

        return $new;
    }

    /** Days from a to b. */
    private function days(string $a, string $b): int
    {
        return (int) CarbonImmutable::parse($a)->diffInDays(CarbonImmutable::parse($b), false);
    }
}
