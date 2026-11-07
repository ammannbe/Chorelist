<?php

namespace Database\Seeders;

use App\Models\Completion;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Support\Schedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database (local development): test@example.com and
     * anna@example.com (password "password") share a list with some chores.
     */
    public function run(): void
    {
        $user = User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com', 'locale' => 'de_CH']);
        $anna = User::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com', 'locale' => 'de_CH']);

        $list = new TaskList(['name' => 'WG', 'icon' => '🏠']);
        $list->id = (string) Str::uuid7();
        $list->owner()->associate($user);
        $list->save();
        $list->addMember($user);
        $list->addMember($anna);

        $today = Schedule::today();
        $tasks = [
            ['title' => 'Bad putzen', 'icon' => '🛁', 'points' => 4, 'mode' => 'fixed', 'rrule' => 'FREQ=WEEKLY;BYDAY=SA', 'window_days' => 1, 'reminders' => [0]],
            ['title' => 'Staubsaugen', 'icon' => '🧹', 'points' => 3, 'mode' => 'after_completion', 'rrule' => 'FREQ=DAILY;INTERVAL=5'],
            ['title' => 'Altpapier bündeln', 'icon' => '📦', 'points' => 2, 'mode' => 'fixed', 'rrule' => 'FREQ=MONTHLY;BYDAY=4MO', 'reminders' => [1]],
            ['title' => 'Müll entsorgen', 'icon' => '🗑️', 'points' => 1, 'mode' => 'on_demand', 'remind_after_days' => 4],
            ['title' => 'Pflanzen giessen', 'icon' => '🪴', 'points' => 1, 'mode' => 'after_completion', 'rrule' => 'FREQ=DAILY;INTERVAL=3'],
            ['title' => 'Kühlschrank ausmisten', 'icon' => '🧊', 'points' => 3, 'mode' => 'fixed', 'rrule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=FR', 'window_days' => 2],
            ['title' => 'Kartonsammlung', 'icon' => '📦', 'category' => 'Entsorgung', 'points' => 1, 'mode' => 'dates', 'reminders' => [1],
                'dates' => array_map(fn (int $days) => Schedule::addDays($today, $days), [-11, 3, 31, 59])],
        ];
        foreach ($tasks as $attributes) {
            $task = new Task([...$attributes, 'starts_on' => $attributes['dates'][0] ?? Schedule::addDays($today, -14)]);
            $task->id = (string) Str::uuid7();
            $task->taskList()->associate($list);
            $task->creator()->associate($user);
            $task->save();
        }

        // Some history for the ranking.
        foreach ([[$user, 'Staubsaugen', -6], [$anna, 'Müll entsorgen', -3], [$anna, 'Pflanzen giessen', -2], [$user, 'Müll entsorgen', -1]] as [$who, $title, $days]) {
            $task = Task::firstWhere('title', $title);
            $completion = new Completion([
                'occurrence' => null,
                'done_on' => Schedule::addDays($today, $days),
                'type' => $task->mode === 'on_demand' ? 'done' : 'extra',
                'points' => $task->points,
            ]);
            $completion->id = (string) Str::uuid7();
            $completion->task()->associate($task);
            $completion->task_list_id = $list->id;
            $completion->user()->associate($who);
            $completion->enteredBy()->associate($who);
            $completion->save();
        }
    }
}
