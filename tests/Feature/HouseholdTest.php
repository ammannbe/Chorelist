<?php

namespace Tests\Feature;

use App\Models\Completion;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Notifications\HouseholdInvited;
use App\Notifications\ListShared;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HouseholdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 12:00');
    }

    private function push(User $user, string $collection, array $rows): TestResponse
    {
        return $this->actingAs($user)->postJson("/sync/{$collection}/push", $rows);
    }

    private function pull(User $user, string $collection, array $checkpoint = []): TestResponse
    {
        return $this->actingAs($user)->getJson("/sync/{$collection}/pull?".http_build_query($checkpoint));
    }

    private function createList(User $user): string
    {
        $id = (string) Str::uuid();
        $this->push($user, 'lists', [['newDocumentState' => ['id' => $id, 'name' => 'Familie']]])->assertExactJson([]);

        return $id;
    }

    private function createTask(User $user, string $listId): string
    {
        $id = (string) Str::uuid();
        $this->push($user, 'tasks', [['newDocumentState' => [
            'id' => $id, 'task_list_id' => $listId, 'title' => 'Bad putzen', 'points' => 3,
            'mode' => 'fixed', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO', 'starts_on' => '2026-11-02',
        ]]])->assertOk()->assertExactJson([]);

        return $id;
    }

    private function household(User $admin, User ...$members): Household
    {
        $household = Household::create(['name' => 'Familie Muster', 'created_by' => $admin->id]);
        $household->addUser($admin, Household::ADMIN);
        foreach ($members as $member) {
            $household->addUser($member);
        }

        return $household;
    }

    private function share(User $owner, string $listId, Household $household): TestResponse
    {
        return $this->actingAs($owner)->postJson("/lists/{$listId}/households", ['household' => $household->uuid]);
    }

    /**
     * Owner with a list shared with a household of owner and member, plus an outsider.
     *
     * @return array{0: User, 1: User, 2: User, 3: Household, 4: string, 5: string}
     */
    private function shared(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $household = $this->household($owner, $member);
        $listId = $this->createList($owner);
        $taskId = $this->createTask($owner, $listId);
        $this->share($owner, $listId, $household)->assertOk()->assertJson(['status' => 'shared']);

        return [$owner, $member, $outsider, $household, $listId, $taskId];
    }

    private function listDoc(User $user): ?array
    {
        return $this->pull($user, 'lists')->json('documents.0');
    }

    public function test_household_members_see_and_change_shared_lists_others_do_not(): void
    {
        Notification::fake();
        [$owner, $member, $outsider, , $listId, $taskId] = $this->shared();

        $doc = $this->listDoc($member);
        $this->assertSame($listId, $doc['id']);
        $this->assertFalse($doc['_deleted']);
        $this->assertFalse($doc['direct']);
        $this->assertSame([['id' => Household::first()->uuid, 'name' => 'Familie Muster']], $doc['households']);
        $this->assertSame([$taskId], $this->pull($member, 'tasks')->json('documents.*.id'));
        Notification::assertSentTo($member, ListShared::class);

        // Members through a household may change tasks like any member.
        $task = $this->pull($member, 'tasks')->json('documents.0');
        $this->push($member, 'tasks', [['assumedMasterState' => $task, 'newDocumentState' => [...$task, 'title' => 'Bad', 'version' => $task['version'] + 1]]])
            ->assertExactJson([]);
        $this->assertSame('Bad', Task::find($taskId)->title);

        $this->assertSame([], $this->pull($outsider, 'lists')->json('documents'));
        $this->assertSame([], $this->pull($outsider, 'tasks')->json('documents'));
        $this->push($outsider, 'tasks', [['assumedMasterState' => $task, 'newDocumentState' => [...$task, 'title' => 'Hack', 'version' => 99]]]);
        $this->assertSame('Bad', Task::find($taskId)->title);
    }

    public function test_removed_members_lose_access_and_their_pushes_are_rejected(): void
    {
        [$owner, $member, , $household, $listId, $taskId] = $this->shared();
        $checkpoint = $this->pull($member, 'lists')->json('checkpoint');
        $task = $this->pull($member, 'tasks')->json('documents.0');
        Carbon::setTestNow(now()->addMinute());

        $this->actingAs($owner)->delete("/households/{$household->uuid}/members/{$member->id}")->assertRedirect("/households#household-{$household->uuid}");

        // The next pull removes the list from the device.
        $this->assertTrue($this->pull($member, 'lists', $checkpoint)->json('documents.0._deleted'));
        $this->assertSame([], $this->pull($member, 'tasks')->json('documents'));
        $this->assertFalse(TaskList::find($listId)->hasMember($member));

        $this->push($member, 'tasks', [['assumedMasterState' => $task, 'newDocumentState' => [...$task, 'title' => 'Offline', 'version' => $task['version'] + 1]]]);
        $this->assertSame('Bad putzen', Task::find($taskId)->title);
        $this->push($member, 'tasks', [['newDocumentState' => [
            'id' => (string) Str::uuid(), 'task_list_id' => $listId, 'title' => 'Neu', 'mode' => 'once', 'starts_on' => '2026-11-10',
        ]]]);
        $this->assertSame(1, Task::count());
        $this->push($member, 'completions', [['newDocumentState' => [
            'id' => Completion::occurrenceId($taskId, '2026-11-09'), 'task_id' => $taskId,
            'occurrence' => '2026-11-09', 'done_on' => '2026-11-09', 'type' => 'done',
        ]]]);
        $this->assertSame(0, Completion::count());
        $this->push($owner, 'completions', [['newDocumentState' => [
            'id' => Completion::occurrenceId($taskId, '2026-11-09'), 'task_id' => $taskId,
            'occurrence' => '2026-11-09', 'done_on' => '2026-11-09', 'type' => 'done',
        ]]])->assertExactJson([]);
        $this->assertSame(1, Completion::count());

        // The list stays with its owner.
        $this->assertFalse($this->listDoc($owner)['_deleted']);
    }

    public function test_members_leaving_the_household_lose_access(): void
    {
        [, $member, , $household, $listId] = $this->shared();

        $this->actingAs($member)->delete("/households/{$household->uuid}/members/{$member->id}")
            ->assertRedirect('/households')->assertSessionHas('status');

        $this->assertTrue($this->listDoc($member)['_deleted']);
        $this->assertFalse(TaskList::find($listId)->hasMember($member));
    }

    public function test_deleting_the_household_removes_only_the_access(): void
    {
        [$owner, $member, , $household, $listId, $taskId] = $this->shared();

        $this->actingAs($member)->delete("/households/{$household->uuid}")->assertForbidden();
        $this->actingAs($owner)->delete("/households/{$household->uuid}")->assertRedirect('/households');

        $this->assertNull(Household::find($household->id));
        $this->assertTrue($this->listDoc($member)['_deleted']);
        $this->assertSame([], $this->pull($member, 'tasks')->json('documents'));
        $this->assertFalse($this->listDoc($owner)['_deleted']);
        $this->assertNotNull(Task::find($taskId));
    }

    public function test_new_household_members_get_access_to_shared_lists(): void
    {
        [$owner, , , $household, $listId] = $this->shared();
        $late = User::factory()->create();
        $this->assertSame([], $this->pull($late, 'lists')->json('documents'));

        $household->addUser($late);

        $this->assertSame($listId, $this->listDoc($late)['id']);
        $this->assertCount(1, $this->pull($late, 'tasks')->json('documents'));
        $this->assertCount(3, $this->listDoc($owner)['members']);
    }

    public function test_unsharing_keeps_direct_members(): void
    {
        [$owner, $member, , $household, $listId] = $this->shared();
        $direct = User::factory()->create();
        $household->addUser($direct);
        TaskList::find($listId)->addMember($direct);

        $this->actingAs($member)->deleteJson("/lists/{$listId}/households/{$household->uuid}")->assertForbidden();
        $this->actingAs($owner)->deleteJson("/lists/{$listId}/households/{$household->uuid}")->assertNoContent();

        $this->assertTrue($this->listDoc($member)['_deleted']);
        $this->assertFalse($this->listDoc($direct)['_deleted']);
        $this->assertTrue($this->listDoc($direct)['direct']);
        $this->assertSame([], $this->listDoc($owner)['households']);
    }

    public function test_household_members_leave_the_household_not_the_list(): void
    {
        [$owner, $member, , , $listId] = $this->shared();

        $this->actingAs($member)->deleteJson("/lists/{$listId}/members/{$member->email}")->assertUnprocessable();
        $this->actingAs($owner)->deleteJson("/lists/{$listId}/members/{$member->email}")->assertUnprocessable();

        // Deleting the list on the device brings it back.
        $doc = $this->listDoc($member);
        $this->push($member, 'lists', [['assumedMasterState' => $doc, 'newDocumentState' => [...$doc, '_deleted' => true]]])
            ->assertJsonPath('0._deleted', false);
        $this->assertTrue(TaskList::find($listId)->hasMember($member));
    }

    public function test_removing_a_direct_member_keeps_the_household_access(): void
    {
        [$owner, $member, , , $listId] = $this->shared();
        TaskList::find($listId)->addMember($member);
        $this->assertTrue($this->listDoc($member)['direct']);

        $this->actingAs($owner)->deleteJson("/lists/{$listId}/members/{$member->email}")->assertNoContent();

        $doc = $this->listDoc($member);
        $this->assertFalse($doc['_deleted']);
        $this->assertFalse($doc['direct']);
    }

    public function test_only_owners_share_and_only_with_their_households(): void
    {
        [$owner, $member, $outsider, $household, $listId] = $this->shared();
        $foreign = $this->household($outsider);

        $this->actingAs($member)->postJson("/lists/{$listId}/households", ['household' => $household->uuid])->assertForbidden();
        $this->share($owner, $listId, $foreign)->assertNotFound();
        $this->assertFalse(TaskList::find($listId)->hasMember($outsider));
    }

    public function test_removed_members_no_longer_share_their_own_lists(): void
    {
        [$owner, $member, , $household] = $this->shared();
        $memberList = $this->createList($member);
        $this->share($member, $memberList, $household)->assertOk();
        $this->assertSame(2, collect($this->pull($owner, 'lists')->json('documents'))->where('_deleted', false)->count());

        $this->actingAs($owner)->delete("/households/{$household->uuid}/members/{$member->id}");

        $this->assertFalse(TaskList::find($memberList)->hasMember($owner));
        $this->assertSame(0, $household->taskLists()->whereKey($memberList)->count());
        $this->assertFalse(collect($this->pull($member, 'lists')->json('documents'))->firstWhere('id', $memberList)['_deleted']);
    }

    public function test_renaming_updates_the_list_documents(): void
    {
        [$owner, $member, , $household] = $this->shared();
        $checkpoint = $this->pull($member, 'lists')->json('checkpoint');
        Carbon::setTestNow(now()->addMinute());

        $this->actingAs($member)->patch("/households/{$household->uuid}", ['name' => 'WG'])->assertForbidden();
        $this->actingAs($owner)->patch("/households/{$household->uuid}", ['name' => 'WG'])->assertRedirect("/households#household-{$household->uuid}");

        $this->assertSame('WG', $this->pull($member, 'lists', $checkpoint)->json('documents.0.households.0.name'));
    }

    public function test_at_least_one_admin_remains(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($admin, $member);

        $this->actingAs($admin)->delete("/households/{$household->uuid}/members/{$admin->id}")->assertSessionHasErrors('household');
        $this->actingAs($admin)->patch("/households/{$household->uuid}/members/{$admin->id}", ['role' => 'member'])->assertSessionHasErrors('household');
        $this->assertTrue($household->isAdmin($admin));

        // Members cannot change roles or remove others.
        $this->actingAs($member)->patch("/households/{$household->uuid}/members/{$member->id}", ['role' => 'admin'])->assertForbidden();
        $this->actingAs($member)->delete("/households/{$household->uuid}/members/{$admin->id}")->assertForbidden();

        $this->actingAs($admin)->patch("/households/{$household->uuid}/members/{$member->id}", ['role' => 'admin'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->delete("/households/{$household->uuid}/members/{$admin->id}")->assertSessionHasNoErrors();
        $this->assertFalse($household->hasUser($admin));
        $this->assertTrue($household->isAdmin($member));
    }

    public function test_households_are_created_and_listed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/households', ['name' => ' Familie Muster ']);

        $household = Household::firstOrFail();
        $response->assertRedirect("/households#household-{$household->uuid}");
        $this->assertSame('Familie Muster', $household->name);
        $this->assertTrue($household->isAdmin($user));
        $this->actingAs($user)->get('/households')->assertOk()->assertSee('Familie Muster');
        $this->actingAs($user)->getJson('/me')->assertJsonPath('households.0', ['id' => $household->uuid, 'name' => 'Familie Muster', 'role' => 'admin']);

        // Non-members do not see it.
        $this->actingAs(User::factory()->create())->get('/households')->assertOk()->assertDontSee('Familie Muster');
        $this->actingAs(User::factory()->create())->patch("/households/{$household->uuid}", ['name' => 'X'])->assertNotFound();
    }

    public function test_the_households_page_shows_every_household_with_its_details(): void
    {
        [$owner, $member, , $household] = $this->shared();
        $other = Household::create(['name' => 'Zweiter Haushalt', 'created_by' => $owner->id]);
        $other->addUser($owner, Household::ADMIN);
        $opened = fn (Household $household) => '/<details id="household-'.$household->uuid.'"[^>]*\sopen>/';

        // Links to a household open it on the page (non-members: not found).
        $this->actingAs(User::factory()->create())->get("/households/{$household->uuid}")->assertNotFound();
        $this->actingAs($owner)->get("/households/{$household->uuid}")
            ->assertRedirect("/households#household-{$household->uuid}")->assertSessionHas('household', $household->uuid);
        $page = $this->get('/households')->assertOk()
            ->assertSee($member->email)->assertSee('Zweiter Haushalt')
            ->assertSee(route('households.invitations.store', $household))
            ->getContent();
        $this->assertMatchesRegularExpression($opened($household), $page);
        $this->assertDoesNotMatchRegularExpression($opened($other), $page);

        // A failed form opens its household again.
        $this->from('/households')->post("/households/{$other->uuid}/invitations", ['_household' => $other->uuid, 'email' => 'no-email'])
            ->assertRedirect('/households')->assertSessionHasErrors('email');
        $this->assertMatchesRegularExpression($opened($other), $this->get('/households')->getContent());

        // Members see the household without the management.
        $this->actingAs($member)->get('/households')->assertOk()->assertSee('Familie Muster')
            ->assertDontSee(route('households.invitations.store', $household))->assertDontSee(__('Delete household'));
    }

    public function test_invitations_are_accepted_by_the_invited_address(): void
    {
        Notification::fake();
        [$owner, , $outsider, $household, $listId] = $this->shared();
        $invitee = User::factory()->create(['email' => 'anna@example.com']);

        $this->actingAs($owner)->post("/households/{$household->uuid}/invitations", ['email' => 'Anna@Example.com'])
            ->assertRedirect("/households#household-{$household->uuid}")->assertSessionHas('status');

        $path = null;
        Notification::assertSentOnDemand(HouseholdInvited::class, function (HouseholdInvited $notification, array $channels, AnonymousNotifiable $notifiable) use (&$path) {
            $path = parse_url($notification->toMail($notifiable)->actionUrl, PHP_URL_PATH);

            return $notifiable->routes['mail'] === 'anna@example.com';
        });
        $this->actingAs($invitee)->getJson('/me')->assertJsonPath('household_invitations', 1);

        // Another user with the link cannot join.
        $this->actingAs($outsider)->get($path)->assertOk()->assertSee('anna@example.com');
        $this->actingAs($outsider)->post($path)->assertSessionHasErrors('household');
        $this->assertFalse($household->hasUser($outsider));

        $this->actingAs($invitee)->get($path)->assertOk()->assertSee('Familie Muster');
        $this->actingAs($invitee)->post($path)->assertRedirect("/households#household-{$household->uuid}");
        $this->assertSame(Household::MEMBER, $household->roleOf($invitee));
        $this->assertSame($listId, $this->listDoc($invitee)['id']);

        // Used up.
        $this->actingAs($invitee)->post($path)->assertSessionHasErrors('household');
        $this->actingAs($owner)->post("/households/{$household->uuid}/invitations", ['email' => $invitee->email])->assertSessionHasErrors('household');
    }

    public function test_open_invitations_are_accepted_or_declined_on_the_households_page(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $household = $this->household($admin);
        $invitee = User::factory()->create();
        [$invitation] = HouseholdInvitation::issue($household, $admin, $invitee->email, Household::ADMIN);

        $this->actingAs($invitee)->get('/households')->assertOk()->assertSee('Familie Muster');
        $this->actingAs(User::factory()->create())->post("/household-invitations/{$invitation->id}/accept")->assertSessionHasErrors('household');
        $this->actingAs($invitee)->post("/household-invitations/{$invitation->id}/accept")->assertSessionHasNoErrors();
        $this->assertTrue($household->isAdmin($invitee));

        $other = User::factory()->create();
        [$declined] = HouseholdInvitation::issue($household, $admin, $other->email, Household::MEMBER);
        $this->actingAs($other)->delete("/household-invitations/{$declined->id}")->assertRedirect('/households');
        $this->assertNull(HouseholdInvitation::find($declined->id));
        $this->assertFalse($household->hasUser($other));
    }

    public function test_invitations_expire_and_need_a_verified_address(): void
    {
        $admin = User::factory()->create();
        $household = $this->household($admin);
        $invitee = User::factory()->create();
        [, $token] = HouseholdInvitation::issue($household, $admin, $invitee->email, Household::MEMBER);

        Carbon::setTestNow(now()->addDays(HouseholdInvitation::DAYS + 1));
        $this->actingAs($invitee)->get("/household-invitations/{$token}")->assertRedirect('/households');
        $this->actingAs($invitee)->post("/household-invitations/{$token}")->assertSessionHasErrors('household');
        $this->assertFalse($household->hasUser($invitee));
        $this->actingAs($invitee)->getJson('/me')->assertJsonPath('household_invitations', 0);

        $unverified = User::factory()->unverified()->create();
        [, $token] = HouseholdInvitation::issue($household, $admin, $unverified->email, Household::MEMBER);
        $this->actingAs($unverified)->post("/household-invitations/{$token}")->assertRedirect('/email/verify');
        $this->assertFalse($household->hasUser($unverified));
    }

    public function test_only_admins_invite(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($admin, $member);

        $this->actingAs($member)->post("/households/{$household->uuid}/invitations", ['email' => 'x@example.com'])->assertForbidden();
        $this->actingAs(User::factory()->create())->post("/households/{$household->uuid}/invitations", ['email' => 'x@example.com'])->assertNotFound();
        Notification::assertNothingSent();
    }

    public function test_the_account_manager_driver_makes_local_management_read_only(): void
    {
        Notification::fake();
        [$owner, $member, , $household, $listId] = $this->shared();
        [, $token] = HouseholdInvitation::issue($household, $owner, 'neu@example.com', Household::MEMBER);
        config(['households.driver' => 'account-manager']);

        $this->actingAs($owner)->post('/households', ['name' => 'Neu'])->assertForbidden();
        $this->actingAs($owner)->patch("/households/{$household->uuid}", ['name' => 'Neu'])->assertForbidden();
        $this->actingAs($owner)->delete("/households/{$household->uuid}")->assertForbidden();
        $this->actingAs($owner)->post("/households/{$household->uuid}/invitations", ['email' => 'x@example.com'])->assertForbidden();
        $this->actingAs($owner)->patch("/households/{$household->uuid}/members/{$member->id}", ['role' => 'admin'])->assertForbidden();
        $this->actingAs($owner)->delete("/households/{$household->uuid}/members/{$member->id}")->assertForbidden();
        $this->actingAs($member)->delete("/households/{$household->uuid}/members/{$member->id}")->assertForbidden();
        $this->actingAs(User::factory()->create(['email' => 'neu@example.com']))->post("/household-invitations/{$token}")->assertForbidden();
        $this->assertSame(1, Household::count());
        $this->assertTrue($household->hasUser($member));

        // Read only page, and the app still decides what is shared.
        $this->actingAs($owner)->get('/households')->assertOk()->assertSee('Familie Muster')->assertDontSee(route('households.store'));
        $this->actingAs($owner)->deleteJson("/lists/{$listId}/households/{$household->uuid}")->assertNoContent();
        $this->share($owner, $listId, $household)->assertOk();
    }

    public function test_deleting_the_account_hands_households_over(): void
    {
        [$owner, $member, , $household, $listId] = $this->shared();
        $alone = $this->household($owner);

        $this->actingAs($owner)->delete('/account', ['password' => 'password'])->assertRedirect('/?deleted=1');

        $this->assertTrue($household->isAdmin($member));
        $this->assertNull(Household::find($alone->id));
        $this->assertTrue($this->listDoc($member)['_deleted']);
        $this->assertSame($listId, $this->listDoc($member)['id']);
    }
}
