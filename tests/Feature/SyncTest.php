<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Completion;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Notifications\TaskChanged;
use App\Notifications\TaskCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    private function push(User $user, string $collection, array $rows): TestResponse
    {
        return $this->actingAs($user)->postJson("/sync/{$collection}/push", $rows);
    }

    private function pull(User $user, string $collection, array $checkpoint = []): TestResponse
    {
        return $this->actingAs($user)->getJson("/sync/{$collection}/pull?".http_build_query($checkpoint));
    }

    private function createList(User $user, array $attributes = []): string
    {
        $id = (string) Str::uuid();
        $this->push($user, 'lists', [['newDocumentState' => ['id' => $id, 'name' => 'WG', ...$attributes]]])
            ->assertOk()->assertExactJson([]);

        return $id;
    }

    private function createTask(User $user, string $listId, array $attributes = []): string
    {
        $id = (string) Str::uuid();
        $this->push($user, 'tasks', [['newDocumentState' => [
            'id' => $id, 'task_list_id' => $listId, 'title' => 'Bad putzen', 'points' => 3,
            'mode' => 'fixed', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO', 'starts_on' => '2026-11-02',
            ...$attributes,
        ]]])->assertOk()->assertExactJson([]);

        return $id;
    }

    private function complete(User $user, string $taskId, string $occurrence, array $attributes = []): TestResponse
    {
        return $this->push($user, 'completions', [['newDocumentState' => [
            'id' => Completion::occurrenceId($taskId, $occurrence), 'task_id' => $taskId,
            'occurrence' => $occurrence, 'done_on' => $occurrence, 'type' => 'done',
            ...$attributes,
        ]]]);
    }

    private function shared(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $listId = $this->createList($owner);
        TaskList::find($listId)->addMember($member);

        return [$owner, $member, $listId];
    }

    public function test_guests_and_unverified_users_cannot_sync(): void
    {
        $this->getJson('/sync/lists/pull')->assertUnauthorized();

        $this->actingAs(User::factory()->unverified()->create())
            ->getJson('/sync/lists/pull')->assertForbidden();
    }

    public function test_unknown_collections_are_not_found(): void
    {
        $this->pull(User::factory()->create(), 'users')->assertNotFound();
    }

    public function test_lists_and_tasks_are_created_and_pulled(): void
    {
        $user = User::factory()->create();
        $listId = $this->createList($user, ['icon' => '🏠', 'ranking_period' => 'week']);
        $taskId = $this->createTask($user, $listId, ['reminders' => [1, 0], 'checklist' => [['id' => 'a', 'text' => 'Spiegel']]]);

        $this->pull($user, 'lists')->assertOk()
            ->assertJsonPath('documents.0.id', $listId)
            ->assertJsonPath('documents.0.icon', '🏠')
            ->assertJsonPath('documents.0.ranking_enabled', true)
            ->assertJsonPath('documents.0.ranking_period', 'week')
            ->assertJsonPath('documents.0.is_owner', true)
            ->assertJsonPath('documents.0.members.0.email', $user->email);

        $response = $this->pull($user, 'tasks')->assertOk()
            ->assertJsonPath('documents.0.id', $taskId)
            ->assertJsonPath('documents.0.starts_on', '2026-11-02')
            ->assertJsonPath('documents.0.reminders', [1, 0])
            ->assertJsonPath('documents.0.checklist.0', ['id' => 'a', 'text' => 'Spiegel', 'done' => false])
            ->assertJsonPath('documents.0.creator_email', $user->email);

        // Nothing new after the returned checkpoint.
        $this->pull($user, 'tasks', $response->json('checkpoint'))->assertJsonCount(0, 'documents');

        $this->pull($user, 'activities')->assertJsonPath('documents.0.action', 'list.created')
            ->assertJsonPath('documents.1.action', 'task.created')
            ->assertJsonPath('documents.1.data.title', 'Bad putzen');
    }

    public function test_invalid_rules_fall_back_to_weekly(): void
    {
        $user = User::factory()->create();
        $taskId = $this->createTask($user, $this->createList($user), ['rrule' => 'FREQ=DAILY;COUNT=2']);

        $this->assertSame('FREQ=WEEKLY', Task::find($taskId)->rrule);
        $this->push($user, 'tasks', [['newDocumentState' => [
            'id' => (string) Str::uuid(), 'task_list_id' => Task::find($taskId)->task_list_id, 'title' => 'X', 'mode' => 'sometimes', 'starts_on' => '2026-11-02',
        ]]])->assertUnprocessable();
    }

    public function test_task_changes_are_recorded_and_stale_updates_conflict(): void
    {
        [$owner, $member, $listId] = $this->shared();
        $taskId = $this->createTask($owner, $listId);
        $doc = $this->pull($member, 'tasks')->json('documents.0');

        // Members may change tasks too.
        $this->push($member, 'tasks', [['assumedMasterState' => $doc, 'newDocumentState' => [...$doc, 'points' => 5, 'version' => 2]]])
            ->assertExactJson([]);
        $this->assertSame(5, Task::find($taskId)->points);
        $activity = Activity::where('action', 'task.updated')->sole();
        $this->assertSame($member->id, $activity->user_id);
        $this->assertSame(['points' => [3, 5]], $activity->data['changes']);

        // The owner did not see that change yet.
        $this->push($owner, 'tasks', [['assumedMasterState' => $doc, 'newDocumentState' => [...$doc, 'title' => 'Küche', 'version' => 2]]])
            ->assertJsonPath('0.points', 5)
            ->assertJsonPath('0.title', 'Bad putzen');
    }

    public function test_tasks_of_lists_created_offline_are_retried_later(): void
    {
        $user = User::factory()->create();
        $this->push($user, 'tasks', [['newDocumentState' => [
            'id' => (string) Str::uuid(), 'task_list_id' => (string) Str::uuid(), 'title' => 'X', 'mode' => 'on_demand', 'starts_on' => '2026-11-02',
        ]]])->assertStatus(409);
    }

    public function test_foreign_lists_are_neither_visible_nor_writable(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $listId = $this->createList($owner);
        $taskId = $this->createTask($owner, $listId);

        $this->pull($stranger, 'lists')->assertJsonCount(0, 'documents');
        $this->pull($stranger, 'tasks')->assertJsonCount(0, 'documents');
        $this->createTask($stranger, $listId, ['title' => 'Hijack']);
        $this->complete($stranger, $taskId, '2026-11-02')->assertExactJson([]);

        $this->assertSame(1, Task::count());
        $this->assertSame(0, Completion::count());
    }

    public function test_completions_get_the_points_of_the_task_and_notify_the_others(): void
    {
        Notification::fake();
        [$owner, $member, $listId] = $this->shared();
        $member->forceFill(['preferences' => ['notifications' => ['completed' => ['mail']]]])->save();
        $taskId = $this->createTask($owner, $listId, ['points' => 4]);

        $this->complete($owner, $taskId, '2026-11-02')->assertExactJson([]);

        $completion = Completion::sole();
        $this->assertSame([4, $owner->id, $owner->id], [$completion->points, $completion->user_id, $completion->entered_by_id]);
        $this->pull($member, 'completions')->assertJsonPath('documents.0.user_email', $owner->email)
            ->assertJsonPath('documents.0.task_title', 'Bad putzen')
            ->assertJsonPath('documents.0.points', 4);
        Notification::assertSentTo($member, TaskCompleted::class);
        Notification::assertNotSentTo($owner, TaskCompleted::class);

        // Changing the points later keeps the earned ones.
        Task::find($taskId)->update(['points' => 1]);
        $this->assertSame(4, $completion->fresh()->points);
    }

    public function test_occurrence_completions_need_the_derived_id(): void
    {
        $user = User::factory()->create();
        $taskId = $this->createTask($user, $this->createList($user));

        $this->complete($user, $taskId, '2026-11-02', ['id' => (string) Str::uuid()])->assertExactJson([]);
        $this->assertSame(0, Completion::count());

        // Extra completions have no occurrence and any id.
        $this->complete($user, $taskId, '2026-11-02', ['id' => (string) Str::uuid(), 'occurrence' => null, 'type' => 'extra'])->assertExactJson([]);
        $this->assertSame(3, Completion::sole()->points);
    }

    public function test_the_same_occurrence_completed_twice_offline_conflicts(): void
    {
        [$owner, $member, $listId] = $this->shared();
        $taskId = $this->createTask($owner, $listId);

        $this->complete($owner, $taskId, '2026-11-02')->assertExactJson([]);
        // The member did not know about it: the server's completion wins (points for the owner only).
        $this->complete($member, $taskId, '2026-11-02')
            ->assertJsonPath('0.user_email', $owner->email);
        $this->assertSame($owner->id, Completion::sole()->user_id);
    }

    public function test_completions_can_be_undone_and_done_again(): void
    {
        $user = User::factory()->create();
        $taskId = $this->createTask($user, $this->createList($user));
        $this->complete($user, $taskId, '2026-11-02');
        $doc = $this->pull($user, 'completions')->json('documents.0');

        $undone = [...$doc, 'type' => 'undone', 'version' => 2];
        $this->push($user, 'completions', [['assumedMasterState' => $doc, 'newDocumentState' => $undone]])->assertExactJson([]);
        $this->assertSame([0, 'undone'], [Completion::sole()->points, Completion::sole()->type]);
        $this->assertSame(1, Activity::where('action', 'completion.undone')->count());

        $this->push($user, 'completions', [['assumedMasterState' => $undone, 'newDocumentState' => [...$undone, 'type' => 'done', 'version' => 3]]])
            ->assertExactJson([]);
        $this->assertSame([3, 'done'], [Completion::sole()->points, Completion::sole()->type]);
    }

    public function test_completions_of_others_cannot_be_taken_over_moved_or_deleted(): void
    {
        [$owner, $member, $listId] = $this->shared();
        $taskId = $this->createTask($owner, $listId);
        $this->complete($owner, $taskId, '2026-11-02');
        $doc = $this->pull($member, 'completions')->json('documents.0');

        // Points of the owner stay theirs, and nobody deletes an occurrence completion.
        foreach ([
            ['user_email' => $member->email, 'version' => 2],
            ['type' => 'undone', 'version' => 2],
            ['_deleted' => true, 'version' => 2],
        ] as $change) {
            $this->push($member, 'completions', [['assumedMasterState' => $doc, 'newDocumentState' => [...$doc, ...$change]]])
                ->assertJsonPath('0.user_email', $owner->email)
                ->assertJsonPath('0.type', 'done');
        }
        $completion = Completion::sole();
        $this->assertSame([$owner->id, 'done', 1, false], [$completion->user_id, $completion->type, $completion->version, $completion->trashed()]);

        // The owner may take it back, then anybody completes the occurrence (but never moves it).
        $this->push($owner, 'completions', [['assumedMasterState' => $doc, 'newDocumentState' => [...$doc, 'type' => 'undone', 'version' => 2]]])
            ->assertExactJson([]);
        $undone = $this->pull($member, 'completions')->json('documents.0');
        $this->push($member, 'completions', [['assumedMasterState' => $undone, 'newDocumentState' => [
            ...$undone, 'type' => 'done', 'user_email' => $member->email, 'occurrence' => '2026-11-09', 'version' => 3,
        ]]])->assertExactJson([]);
        $completion->refresh();
        $this->assertSame([$member->id, 'done', '2026-11-02'], [$completion->user_id, $completion->type, $completion->occurrence->format('Y-m-d')]);
    }

    public function test_members_complete_for_other_members_only(): void
    {
        [$owner, $member, $listId] = $this->shared();
        $stranger = User::factory()->create();
        $taskId = $this->createTask($owner, $listId);

        $this->complete($owner, $taskId, '2026-11-02', ['user_email' => $member->email]);
        $this->complete($owner, $taskId, '2026-11-09', ['user_email' => $stranger->email]);

        $this->assertSame($member->id, Completion::firstWhere('occurrence', '2026-11-02')->user_id);
        $this->assertSame($owner->id, Completion::firstWhere('occurrence', '2026-11-02')->entered_by_id);
        $this->assertSame($owner->id, Completion::firstWhere('occurrence', '2026-11-09')->user_id);
    }

    public function test_postponing_is_recorded(): void
    {
        $user = User::factory()->create();
        $taskId = $this->createTask($user, $this->createList($user));

        $this->complete($user, $taskId, '2026-11-02', ['type' => 'postponed', 'postponed_to' => '2026-11-05', 'done_on' => '2026-11-03']);

        $this->assertSame('2026-11-05', Completion::sole()->postponed_to->format('Y-m-d'));
        $this->assertSame(0, Completion::sole()->points);
        $this->assertSame('2026-11-05', Activity::where('action', 'completion.postponed')->sole()->data['postponed_to']);
    }

    public function test_completions_of_tasks_created_offline_are_retried_later(): void
    {
        $user = User::factory()->create();
        $this->complete($user, (string) Str::uuid(), '2026-11-02')->assertStatus(409);
    }

    public function test_only_owners_change_the_list_members_their_own_settings(): void
    {
        [$owner, $member, $listId] = $this->shared();
        $doc = $this->pull($member, 'lists')->json('documents.0');
        $this->assertFalse($doc['is_owner']);
        $this->assertCount(2, $doc['members']);

        $this->push($member, 'lists', [['assumedMasterState' => $doc, 'newDocumentState' => [...$doc, 'name' => 'Mine', 'muted' => true, 'position' => 3, 'version' => 2]]])
            ->assertJsonPath('0.name', 'WG')
            ->assertJsonPath('0.muted', true)
            ->assertJsonPath('0.version', 2);

        $this->assertSame('WG', TaskList::find($listId)->name);
        $this->pull($owner, 'lists')->assertJsonPath('documents.0.muted', false);

        // Same fields as on the server: no conflict, and the next change does not conflict either.
        $master = $this->pull($member, 'lists')->json('documents.0');
        $this->push($member, 'lists', [['assumedMasterState' => $master, 'newDocumentState' => [...$master, 'muted' => false, 'version' => 3]]])
            ->assertExactJson([]);
    }

    public function test_owners_delete_lists_for_everyone_and_members_leave(): void
    {
        [$owner, $member, $listId] = $this->shared();
        $other = User::factory()->create();
        TaskList::find($listId)->addMember($other);

        $doc = $this->pull($member, 'lists')->json('documents.0');
        $this->push($member, 'lists', [['assumedMasterState' => $doc, 'newDocumentState' => [...$doc, '_deleted' => true]]])->assertExactJson([]);
        $this->pull($member, 'lists')->assertJsonPath('documents.0._deleted', true);
        $this->pull($other, 'lists')->assertJsonPath('documents.0._deleted', false);

        $doc = $this->pull($owner, 'lists')->json('documents.0');
        $this->push($owner, 'lists', [['assumedMasterState' => $doc, 'newDocumentState' => [...$doc, '_deleted' => true]]])->assertExactJson([]);
        $this->pull($other, 'lists')->assertJsonPath('documents.0._deleted', true);
    }

    public function test_new_members_pull_everything_of_the_list(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $listId = $this->createList($owner);
        $taskId = $this->createTask($owner, $listId);
        $this->complete($owner, $taskId, '2026-11-02');
        $checkpoints = collect(['tasks', 'completions', 'activities'])->mapWithKeys(fn ($c) => [$c => $this->pull($member, $c)->json('checkpoint')]);

        TaskList::find($listId)->addMember($member);

        foreach ($checkpoints as $collection => $checkpoint) {
            $this->assertNotEmpty($this->pull($member, $collection, $checkpoint)->json('documents'), $collection);
        }
    }

    public function test_absences_are_own_and_visible_to_co_members(): void
    {
        [$owner, $member] = $this->shared();
        $stranger = User::factory()->create();
        $id = (string) Str::uuid();

        $this->push($member, 'absences', [['newDocumentState' => ['id' => $id, 'starts_on' => '2026-12-24', 'ends_on' => '2026-12-20', 'note' => 'Skiferien']]])
            ->assertExactJson([]);

        $this->pull($member, 'absences')->assertJsonPath('documents.0.starts_on', '2026-12-20')->assertJsonPath('documents.0.is_own', true)
            ->assertJsonPath('documents.0.note', 'Skiferien');
        $doc = $this->pull($owner, 'absences')->assertJsonPath('documents.0.is_own', false)->assertJsonPath('documents.0.note', null)
            ->json('documents.0');
        $this->pull($stranger, 'absences')->assertJsonCount(0, 'documents');

        // Others cannot change it.
        $this->push($owner, 'absences', [['assumedMasterState' => $doc, 'newDocumentState' => [...$doc, '_deleted' => true]]])
            ->assertJsonPath('0._deleted', false);
    }

    public function test_activities_are_pull_only(): void
    {
        $user = User::factory()->create();
        $listId = $this->createList($user);
        $this->push($user, 'activities', [['newDocumentState' => ['id' => (string) Str::uuid(), 'task_list_id' => $listId, 'action' => 'fake']]])
            ->assertExactJson([]);

        $this->assertSame(['list.created'], Activity::pluck('action')->all());
    }

    public function test_others_are_notified_about_task_changes_if_they_want(): void
    {
        Notification::fake();
        [$owner, $member, $listId] = $this->shared();
        $member->forceFill(['preferences' => ['notifications' => ['task_changed' => ['mail']]]])->save();

        $this->createTask($owner, $listId);

        Notification::assertSentTo($member, TaskChanged::class);
        Notification::assertNotSentTo($owner, TaskChanged::class);
    }

    public function test_pull_pages_through_documents_with_the_same_timestamp(): void
    {
        $user = User::factory()->create();
        $listId = $this->createList($user);
        foreach (range(1, 5) as $i) {
            $this->createTask($user, $listId, ['title' => "Task {$i}"]);
        }
        Task::query()->update(['updated_at' => '2026-01-01 10:00:00.000']);

        $seen = [];
        $checkpoint = [];
        do {
            $response = $this->pull($user, 'tasks', [...$checkpoint, 'limit' => 2])->assertOk();
            $seen = [...$seen, ...array_column($response->json('documents'), 'id')];
            $checkpoint = $response->json('checkpoint');
        } while (count($response->json('documents')) > 0);

        $this->assertCount(5, array_unique($seen));
    }
}
