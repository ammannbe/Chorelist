<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\Completion;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Notifications\TaskReminder;
use App\Notifications\WeeklySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TaskList $list;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // Wednesday, 4 November 2026, 09:00 in Zurich.
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00', 'Europe/Zurich'));

        $this->user = User::factory()->create([
            'timezone' => 'Europe/Zurich',
            'preferences' => ['notifications' => ['reminder' => ['mail'], 'overdue' => ['mail'], 'weekly_summary' => ['mail']]],
        ]);
        $this->list = new TaskList(['name' => 'WG']);
        $this->list->id = (string) Str::uuid();
        $this->list->owner()->associate($this->user)->save();
        $this->list->addMember($this->user);
    }

    private function task(array $attributes): Task
    {
        $task = new Task(['title' => 'Bad putzen', 'points' => 3, 'mode' => 'fixed', 'rrule' => 'FREQ=WEEKLY;BYDAY=FR', 'starts_on' => '2026-11-01', ...$attributes]);
        $task->id = (string) Str::uuid();
        $task->taskList()->associate($this->list)->save();

        return $task;
    }

    public function test_reminders_before_the_due_date_are_sent_once(): void
    {
        $this->task(['reminders' => [2, 0]]);
        $this->task(['title' => 'Später', 'reminders' => [1]]);

        $this->artisan('reminders:send')->assertSuccessful();
        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertSentToTimes($this->user, TaskReminder::class, 1);
    }

    public function test_nothing_before_the_reminder_time_or_while_absent(): void
    {
        $this->task(['reminders' => [2]]);
        $this->user->forceFill(['preferences' => [...$this->user->preferences, 'reminder_time' => '10:00']])->save();

        $this->artisan('reminders:send');
        Notification::assertNothingSent();

        $absence = new Absence(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-10']);
        $absence->id = (string) Str::uuid();
        $absence->user()->associate($this->user)->save();
        Carbon::setTestNow(Carbon::parse('2026-11-04 11:00', 'Europe/Zurich'));

        $this->artisan('reminders:send');
        Notification::assertNothingSent();
    }

    public function test_reminder_times_late_in_the_evening_are_reached(): void
    {
        $this->task(['reminders' => [1], 'rrule' => 'FREQ=WEEKLY;BYDAY=TH']);
        $this->user->forceFill(['preferences' => [...$this->user->preferences, 'reminder_time' => '23:58']])->save();
        Carbon::setTestNow(Carbon::parse('2026-11-04 23:55', 'Europe/Zurich'));

        $this->artisan('reminders:send');

        Notification::assertSentToTimes($this->user, TaskReminder::class, 1);
    }

    public function test_overdue_tasks_are_reported_once_per_occurrence(): void
    {
        // Monday was 2 November.
        $task = $this->task(['rrule' => 'FREQ=WEEKLY;BYDAY=MO']);

        $this->artisan('reminders:send');
        Notification::assertSentTo($this->user, TaskReminder::class, fn ($n) => $n->kind() === 'overdue');

        $completion = new Completion(['occurrence' => '2026-11-02', 'done_on' => '2026-11-04', 'type' => 'done', 'points' => 3]);
        $completion->id = Completion::occurrenceId($task->id, '2026-11-02');
        $completion->task()->associate($task);
        $completion->task_list_id = $this->list->id;
        $completion->save();
        $this->artisan('reminders:send');

        Notification::assertSentToTimes($this->user, TaskReminder::class, 1);
    }

    public function test_postponed_occurrences_are_reminded_again(): void
    {
        $task = $this->task(['rrule' => 'FREQ=WEEKLY;BYDAY=MO', 'reminders' => [0]]);
        $this->artisan('reminders:send');
        Notification::assertSentToTimes($this->user, TaskReminder::class, 1);

        // Postponed to Friday: due that day again, then overdue again.
        $completion = new Completion(['occurrence' => '2026-11-02', 'done_on' => '2026-11-04', 'type' => 'postponed', 'postponed_to' => '2026-11-06', 'points' => 0]);
        $completion->id = Completion::occurrenceId($task->id, '2026-11-02');
        $completion->task()->associate($task);
        $completion->task_list_id = $this->list->id;
        $completion->save();
        Carbon::setTestNow(Carbon::parse('2026-11-06 09:00', 'Europe/Zurich'));
        $this->artisan('reminders:send');
        Carbon::setTestNow(Carbon::parse('2026-11-07 09:00', 'Europe/Zurich'));
        $this->artisan('reminders:send');

        Notification::assertSentToTimes($this->user, TaskReminder::class, 3);
    }

    public function test_unchanged_users_are_skipped_until_something_changes(): void
    {
        $this->artisan('reminders:send');
        $this->assertNotNull(Cache::get("reminders:{$this->user->id}"));

        // A new task due today is reminded at the next run.
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:05', 'Europe/Zurich'));
        $this->task(['rrule' => 'FREQ=WEEKLY;BYDAY=WE', 'starts_on' => '2026-11-04', 'reminders' => [0]]);
        $this->artisan('reminders:send');

        Notification::assertSentToTimes($this->user, TaskReminder::class, 1);
    }

    public function test_on_demand_tasks_remind_after_some_days_without_completion(): void
    {
        $task = $this->task(['mode' => 'on_demand', 'rrule' => null, 'remind_after_days' => 3]);
        $completion = new Completion(['done_on' => '2026-11-02', 'type' => 'done', 'points' => 1]);
        $completion->id = (string) Str::uuid();
        $completion->task()->associate($task);
        $completion->task_list_id = $this->list->id;
        $completion->save();

        $this->artisan('reminders:send');
        Notification::assertNothingSent();

        Carbon::setTestNow(Carbon::parse('2026-11-05 09:00', 'Europe/Zurich'));
        $this->artisan('reminders:send');
        Notification::assertSentTo($this->user, TaskReminder::class, fn ($n) => $n->kind() === 'reminder');
    }

    public function test_muted_lists_and_unwanted_kinds_are_left_out(): void
    {
        $this->task(['reminders' => [2]]);
        $this->user->memberships()->update(['muted' => true]);

        $this->artisan('reminders:send');
        Notification::assertNothingSent();

        $this->user->memberships()->update(['muted' => false]);
        $this->user->forceFill(['preferences' => ['notifications' => ['reminder' => []]]])->save();
        $this->artisan('reminders:send');
        Notification::assertNothingSent();
    }

    public function test_deleted_lists_are_left_out(): void
    {
        $this->task(['reminders' => [2]]);
        $kept = new TaskList(['name' => 'Familie']);
        $kept->id = (string) Str::uuid();
        $kept->owner()->associate($this->user)->save();
        $kept->addMember($this->user);
        $this->list->delete();
        $this->list = $kept;
        $this->task(['title' => 'Staubsaugen', 'reminders' => [2]]);
        // Monday: the weekly summary must not fail on the deleted list either.
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00', 'Europe/Zurich'));
        $this->task(['title' => 'Heute', 'reminders' => [0], 'rrule' => 'FREQ=WEEKLY;BYDAY=MO']);

        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertSentToTimes($this->user, TaskReminder::class, 1);
        Notification::assertSentTo($this->user, WeeklySummary::class);
    }

    public function test_the_weekly_summary_is_sent_on_monday(): void
    {
        $this->task(['reminders' => []]);
        Carbon::setTestNow(Carbon::parse('2026-11-09 08:30', 'Europe/Zurich'));

        $this->artisan('reminders:send');
        $this->artisan('reminders:send');

        Notification::assertSentToTimes($this->user, WeeklySummary::class, 1);
    }
}
