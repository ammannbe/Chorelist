<?php

namespace Tests\Feature;

use App\Http\Controllers\IcalController;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class IcalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_calendar_lists_the_occurrences_with_a_secret_url(): void
    {
        Carbon::setTestNow('2026-11-04 09:00');
        $user = User::factory()->create();
        $list = new TaskList(['name' => 'WG']);
        $list->id = (string) Str::uuid();
        $list->owner()->associate($user)->save();
        $list->addMember($user);
        $task = new Task(['title' => 'Bad putzen, gründlich', 'notes' => "Erste Zeile\rBEGIN:VEVENT", 'mode' => 'fixed', 'rrule' => 'FREQ=WEEKLY;BYDAY=FR', 'starts_on' => '2026-11-01', 'window_days' => 2]);
        $task->id = (string) Str::uuid();
        $task->taskList()->associate($list)->save();

        $this->actingAs($user)->getJson('/me')->assertJsonPath('ical_url', null);
        $url = $this->actingAs($user)->postJson('/ical')->assertOk()->json('url');

        $ics = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->getContent();
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261106\r\nDTEND;VALUE=DATE:20261109", $ics);
        $this->assertStringContainsString('SUMMARY:Bad putzen\, gründlich', $ics);
        $this->assertGreaterThan(20, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringNotContainsString("\rBEGIN:VEVENT\r\nURL", $ics);
        $this->assertStringContainsString('Erste Zeile\nBEGIN:VEVENT', $ics);
        // Wrong or guessed tokens.
        $this->get('/ical/'.$user->id.'-'.str_repeat('0', 40).'.ics')->assertNotFound();
        $this->get('/ical/'.$user->ical_token.'.ics')->assertNotFound();

        $this->actingAs($user)->deleteJson('/ical')->assertNoContent();
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();
    }

    public function test_tasks_on_specific_dates_are_listed_on_these_dates(): void
    {
        Carbon::setTestNow('2026-11-04 09:00');
        $user = User::factory()->create(['ical_token' => Str::random(48)]);
        $list = new TaskList(['name' => 'WG']);
        $list->id = (string) Str::uuid();
        $list->owner()->associate($user)->save();
        $list->addMember($user);
        $task = new Task(['title' => 'Kehricht', 'mode' => 'dates', 'starts_on' => '2026-10-27', 'dates' => ['2026-10-27', '2026-11-10', '2026-11-24', '2027-12-01']]);
        $task->id = (string) Str::uuid();
        $task->taskList()->associate($list)->save();

        $ics = $this->get(parse_url(IcalController::url($user), PHP_URL_PATH))->assertOk()->getContent();

        // The open (overdue) date, the next ones within 180 days.
        $this->assertSame(3, substr_count($ics, 'BEGIN:VEVENT'));
        foreach (['20261027', '20261110', '20261124'] as $date) {
            $this->assertStringContainsString("DTSTART;VALUE=DATE:{$date}", $ics);
        }
    }
}
