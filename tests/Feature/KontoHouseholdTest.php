<?php

namespace Tests\Feature;

use App\Jobs\ApplyKontoEvent;
use App\Models\Household;
use App\Models\HouseholdPendingMember;
use App\Models\KontoWebhookEvent;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * HOUSEHOLDS_DRIVER=konto: households mirrored from the AccountManager (contract: AccountManager
 * docs/HOUSEHOLDS-API.md).
 */
class KontoHouseholdTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'f00dbabe00000000000000000000000000000000000000000000000000000000';

    private const UUID = '019a5e3c-6f1d-7b2a-9c43-0d8e5f6a7b8c';

    /**
     * Answers of the fake AccountManager: "GET households/<uuid>" => [status, body] (or a closure).
     *
     * @var array<string, mixed>
     */
    private array $konto = [];

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 12:00');
        config([
            'households.driver' => 'konto',
            'households.konto' => ['url' => 'https://konto.test', 'key' => 'chorelist', 'secret' => self::SECRET, 'report_shares' => false],
        ]);

        Http::fake(function (HttpRequest $request) {
            $route = $request->method().' '.Str::after($request->url(), 'https://konto.test/api/');
            $answer = $this->konto[$route] ?? [404, ['message' => 'Not found.']];
            if ($answer instanceof \Closure) {
                return $answer($request);
            }

            return Http::response($answer[1], $answer[0]);
        });

        $this->owner = User::factory()->create(['name' => 'Olivia']);
        $this->owner->forceFill(['oidc_sub' => 'sub-owner'])->save();
        $this->member = User::factory()->create(['name' => 'Max']);
        $this->member->forceFill(['oidc_sub' => 'sub-member'])->save();
    }

    private function snapshot(int $version, array $members = ['sub-owner' => 'admin', 'sub-member' => 'member'], string $name = 'Familie Muster', string $uuid = self::UUID): array
    {
        return [
            'uuid' => $uuid,
            'name' => $name,
            'version' => $version,
            'members' => array_map(fn ($sub, $role) => ['sub' => $sub, 'role' => $role], array_keys($members), $members),
        ];
    }

    private function webhook(string $type, array $snapshot, array $options = []): TestResponse
    {
        $body = json_encode([
            'event_id' => $options['event_id'] ?? (string) Str::uuid(),
            'type' => $type,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'data' => ['household' => $snapshot, ...($options['data'] ?? [])],
        ], JSON_UNESCAPED_SLASHES);
        $timestamp = (string) ($options['timestamp'] ?? now()->getTimestamp());
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $options['secret'] ?? self::SECRET);

        return $this->call('POST', '/api/konto/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_KONTO_EVENT' => $type,
            'HTTP_X_KONTO_TIMESTAMP' => $timestamp,
            'HTTP_X_KONTO_SIGNATURE' => $options['signature'] ?? $signature,
        ], content: $body);
    }

    /**
     * The household arrives with household.created (unknown: confirmed through the API).
     */
    private function mirror(array $snapshot): Household
    {
        $this->konto['GET households/'.$snapshot['uuid']] = [200, ['data' => $snapshot]];
        $this->webhook('household.created', $snapshot)->assertNoContent();

        return Household::where('konto_household_uuid', $snapshot['uuid'])->firstOrFail();
    }

    private function pull(User $user, string $collection, array $checkpoint = []): TestResponse
    {
        return $this->actingAs($user)->getJson("/sync/{$collection}/pull?".http_build_query($checkpoint));
    }

    private function push(User $user, string $collection, array $rows): TestResponse
    {
        return $this->actingAs($user)->postJson("/sync/{$collection}/push", $rows);
    }

    private function listDoc(User $user): ?array
    {
        return $this->pull($user, 'lists')->json('documents.0');
    }

    /**
     * A list of $owner with a task, shared with the household.
     */
    private function sharedList(User $owner, Household $household, string $name = 'Familie'): string
    {
        $id = (string) Str::uuid();
        $this->push($owner, 'lists', [['newDocumentState' => ['id' => $id, 'name' => $name]]])->assertExactJson([]);
        $this->push($owner, 'tasks', [['newDocumentState' => [
            'id' => (string) Str::uuid(), 'task_list_id' => $id, 'title' => 'Bad putzen', 'points' => 3,
            'mode' => 'fixed', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO', 'starts_on' => '2026-11-02',
        ]]])->assertExactJson([]);
        $this->actingAs($owner)->postJson("/lists/{$id}/households", ['household' => $household->uuid])->assertOk();

        return $id;
    }

    public function test_events_of_unknown_households_are_confirmed_through_the_api(): void
    {
        $snapshot = $this->snapshot(1, ['sub-owner' => 'admin', 'sub-member' => 'member', 'sub-unknown' => 'member']);
        $household = $this->mirror($snapshot);

        $this->assertSame('Familie Muster', $household->name);
        $this->assertSame(1, $household->konto_version);
        $this->assertSame(Household::ADMIN, $household->roleOf($this->owner));
        $this->assertSame(Household::MEMBER, $household->roleOf($this->member));
        $this->assertSame(['sub-unknown'], $household->pendingMembers()->pluck('oidc_sub')->all());
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET'
            && $request->url() === 'https://konto.test/api/households/'.self::UUID
            && $request->header('Authorization') === ['Basic '.base64_encode('chorelist:'.self::SECRET)]
            && $request->header('Accept') === ['application/json']);

        // An event after the deletion (404) creates nothing.
        $this->konto['GET households/'.self::UUID] = [404, ['message' => 'Not found.']];
        $this->webhook('household.deleted', [...$this->snapshot(2, []), 'deleted' => true])->assertNoContent();
        $this->webhook('household.member_added', $this->snapshot(1))->assertNoContent();
        $this->assertSame(0, Household::count());
        $this->assertSame(0, HouseholdPendingMember::count());
    }

    public function test_invalid_and_expired_signatures_are_rejected(): void
    {
        $snapshot = $this->snapshot(1);
        $this->konto['GET households/'.self::UUID] = [200, ['data' => $snapshot]];

        $this->webhook('household.created', $snapshot, ['secret' => str_repeat('0', 64)])->assertUnauthorized();
        $this->webhook('household.created', $snapshot, ['signature' => ''])->assertUnauthorized();
        $this->webhook('household.created', $snapshot, ['timestamp' => now()->getTimestamp() - 301])->assertUnauthorized();
        $this->webhook('household.created', $snapshot, ['timestamp' => now()->getTimestamp() + 301])->assertUnauthorized();
        $this->webhook('household.created', $snapshot, ['timestamp' => 'abc'])->assertUnauthorized();
        $this->postJson('/api/konto/webhook', ['event_id' => (string) Str::uuid(), 'type' => 'household.created', 'data' => ['household' => $snapshot]])->assertUnauthorized();
        $this->assertSame(0, Household::count());
        $this->assertSame(0, KontoWebhookEvent::count());
        Http::assertNothingSent();

        // Within the tolerance, and the signed body has to be valid.
        $this->webhook('household.created', ['uuid' => 'nope'])->assertUnprocessable();
        $this->webhook('household.created', $snapshot, ['timestamp' => now()->getTimestamp() - 299])->assertNoContent();
        $this->assertSame(1, Household::count());
    }

    public function test_the_local_driver_rejects_webhooks(): void
    {
        config(['households.driver' => 'local']);
        $this->webhook('household.created', $this->snapshot(1))->assertNotFound();

        config(['households.driver' => 'konto', 'households.konto.secret' => null]);
        $this->webhook('household.created', $this->snapshot(1))->assertNotFound();

        $this->assertSame(0, Household::count());
        Http::assertNothingSent();
    }

    public function test_events_are_applied_once(): void
    {
        $this->mirror($this->snapshot(1));
        Queue::fake();
        $eventId = (string) Str::uuid();

        $this->webhook('household.updated', $this->snapshot(2, name: 'Muster'), ['event_id' => $eventId])->assertNoContent();
        $this->webhook('household.updated', $this->snapshot(2, name: 'Muster'), ['event_id' => $eventId])->assertNoContent();

        Queue::assertPushed(ApplyKontoEvent::class, 1);
        $this->assertSame(2, KontoWebhookEvent::count());

        // Unknown types are acknowledged; with a household they count as household.updated.
        $this->webhook('user.deleted', [], ['data' => ['household' => null]])->assertNoContent();
        Queue::assertPushed(ApplyKontoEvent::class, 1);
        $this->travel(8)->days();
        $this->artisan('model:prune', ['--model' => [KontoWebhookEvent::class]])->assertSuccessful();
        $this->assertSame(0, KontoWebhookEvent::count());
    }

    public function test_stale_versions_are_ignored(): void
    {
        $household = $this->mirror($this->snapshot(3));
        $listId = $this->sharedList($this->owner, $household);

        $this->webhook('household.updated', $this->snapshot(3, name: 'Gleich'))->assertNoContent();
        $this->webhook('household.member_removed', $this->snapshot(2, ['sub-owner' => 'admin']))->assertNoContent();
        $this->assertSame('Familie Muster', $household->fresh()->name);
        $this->assertTrue($household->hasUser($this->member));
        $this->assertFalse($this->listDoc($this->member)['_deleted']);

        $checkpoint = $this->pull($this->member, 'lists')->json('checkpoint');
        Carbon::setTestNow(now()->addMinute());
        $this->webhook('household.archived', $this->snapshot(4, name: 'Familie Meier'))->assertNoContent();
        $this->assertSame('Familie Meier', $household->fresh()->name);
        $this->assertSame(4, $household->fresh()->konto_version);
        // The household name is part of the list documents.
        $this->assertSame('Familie Meier', $this->pull($this->member, 'lists', $checkpoint)->json('documents.0.households.0.name'));
        $this->assertSame($listId, $this->listDoc($this->owner)['id']);
    }

    public function test_member_changes_grant_and_revoke_access_to_the_shared_lists(): void
    {
        $household = $this->mirror($this->snapshot(1));
        $listId = $this->sharedList($this->owner, $household);
        $taskId = Task::first()->id;
        $doc = $this->listDoc($this->member);
        $this->assertSame($listId, $doc['id']);
        $this->assertFalse($doc['_deleted']);
        $checkpoint = $this->pull($this->member, 'lists')->json('checkpoint');
        $task = $this->pull($this->member, 'tasks')->json('documents.0');
        Carbon::setTestNow(now()->addMinute());

        // Removed: the next pull removes the list from the device, pushes are ignored.
        $this->webhook('household.member_removed', $this->snapshot(2, ['sub-owner' => 'admin']), ['data' => ['member' => ['sub' => 'sub-member', 'role' => 'member']]])->assertNoContent();
        $this->assertFalse($household->hasUser($this->member));
        $this->assertTrue($this->pull($this->member, 'lists', $checkpoint)->json('documents.0._deleted'));
        $this->assertSame([], $this->pull($this->member, 'tasks')->json('documents'));
        $this->push($this->member, 'tasks', [['assumedMasterState' => $task, 'newDocumentState' => [...$task, 'title' => 'Offline', 'version' => $task['version'] + 1]]]);
        $this->assertSame('Bad putzen', Task::find($taskId)->title);
        $this->assertFalse($this->listDoc($this->owner)['_deleted']);
        $this->assertCount(1, $this->listDoc($this->owner)['members']);

        // Added again (as admin), then the role changes.
        $this->webhook('household.member_added', $this->snapshot(3, ['sub-owner' => 'admin', 'sub-member' => 'admin']))->assertNoContent();
        $this->assertSame(Household::ADMIN, $household->roleOf($this->member));
        $this->assertFalse($this->listDoc($this->member)['_deleted']);
        $this->assertCount(1, $this->pull($this->member, 'tasks')->json('documents'));
        $this->webhook('household.member_updated', $this->snapshot(4, ['sub-owner' => 'admin', 'sub-member' => 'member']))->assertNoContent();
        $this->assertSame(Household::MEMBER, $household->roleOf($this->member));

        // A new member who uses the app already gets the list right away.
        $newcomer = User::factory()->create();
        $newcomer->forceFill(['oidc_sub' => 'sub-newcomer'])->save();
        $this->webhook('household.member_added', $this->snapshot(5, ['sub-owner' => 'admin', 'sub-member' => 'member', 'sub-newcomer' => 'member']))->assertNoContent();
        $this->assertSame($listId, $this->listDoc($newcomer)['id']);
        $this->assertCount(3, $this->listDoc($this->owner)['members']);
    }

    public function test_removed_members_no_longer_share_their_own_lists(): void
    {
        $household = $this->mirror($this->snapshot(1));
        $listId = $this->sharedList($this->member, $household, 'Max');
        $this->assertSame($listId, $this->listDoc($this->owner)['id']);

        $this->webhook('household.member_removed', $this->snapshot(2, ['sub-owner' => 'admin']))->assertNoContent();

        $this->assertSame([], TaskList::find($listId)->households()->pluck('households.id')->all());
        $this->assertTrue($this->listDoc($this->owner)['_deleted']);
        $this->assertFalse($this->listDoc($this->member)['_deleted']);
    }

    public function test_a_deleted_household_removes_only_the_shares(): void
    {
        $household = $this->mirror($this->snapshot(1));
        $listId = $this->sharedList($this->owner, $household);

        $this->webhook('household.deleted', [...$this->snapshot(2, []), 'deleted' => true])->assertNoContent();

        $this->assertNull(Household::find($household->id));
        $this->assertTrue($this->listDoc($this->member)['_deleted']);
        $this->assertSame([], $this->pull($this->member, 'tasks')->json('documents'));
        $doc = $this->listDoc($this->owner);
        $this->assertSame($listId, $doc['id']);
        $this->assertFalse($doc['_deleted']);
        $this->assertSame([], $doc['households']);
        $this->assertSame(1, Task::count());
    }

    private function oidcLogin(string $sub, string $email): TestResponse
    {
        config(['login.oidc.enabled' => true, 'login.oidc.registration' => true, 'login.oidc.required_group' => null]);
        $identity = (new SocialiteUser)->setRaw(['sub' => $sub, 'email' => $email, 'email_verified' => true])
            ->map(['id' => $sub, 'email' => $email, 'name' => 'Neu', 'nickname' => null]);
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($identity);
        Socialite::shouldReceive('driver')->with('oidc')->andReturn($provider);

        return $this->get(route('oidc.callback'));
    }

    public function test_pending_members_are_attached_on_their_first_oidc_login(): void
    {
        $snapshot = $this->snapshot(1, ['sub-owner' => 'admin', 'sub-new' => 'member']);
        $household = $this->mirror($snapshot);
        $listId = $this->sharedList($this->owner, $household);
        $this->assertSame(1, $household->pendingMembers()->count());
        $this->konto['GET households?sub=sub-new'] = [200, ['data' => [$snapshot]]];

        $this->oidcLogin('sub-new', 'neu@example.com')->assertRedirect(config('fortify.home'));

        $user = User::where('oidc_sub', 'sub-new')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(Household::MEMBER, $household->roleOf($user));
        $this->assertSame(0, $household->pendingMembers()->count());
        $this->assertSame($listId, $this->listDoc($user)['id']);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://konto.test/api/households?sub=sub-new');
    }

    public function test_the_login_works_while_the_konto_is_down(): void
    {
        $household = $this->mirror($this->snapshot(1, ['sub-owner' => 'admin', 'sub-new' => 'member']));
        $this->konto['GET households?sub=sub-new'] = fn () => Http::failedConnection();
        Log::spy();

        $this->oidcLogin('sub-new', 'neu@example.com')->assertRedirect(config('fortify.home'));

        $user = User::where('oidc_sub', 'sub-new')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        // The pending membership is known locally.
        $this->assertTrue($household->hasUser($user));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'on login'))->once();
    }

    public function test_unlinking_the_login_leaves_the_mirrored_households(): void
    {
        $household = $this->mirror($this->snapshot(1));
        $this->sharedList($this->owner, $household);

        $this->actingAs($this->member)->delete('/account/oidc')->assertRedirect('/account');

        $this->assertFalse($household->hasUser($this->member));
        $this->assertSame(['sub-member'], $household->pendingMembers()->pluck('oidc_sub')->all());
        $this->assertTrue($this->listDoc($this->member)['_deleted']);
    }

    public function test_the_sync_command_mirrors_all_households(): void
    {
        $kept = $this->mirror($this->snapshot(1));
        $left = $this->mirror($this->snapshot(1, ['sub-owner' => 'admin'], 'Ferienhaus', '019a5e3c-0000-7000-8000-000000000002'));
        $gone = $this->mirror($this->snapshot(1, ['sub-member' => 'admin'], 'WG', '019a5e3c-0000-7000-8000-000000000003'));
        $this->sharedList($this->owner, $left);
        Http::fake(); // only resets the recorded requests, the fake AccountManager stays

        $this->konto = [
            'GET households?sub=sub-owner' => [200, ['data' => [
                $this->snapshot(2, name: 'Familie Meier'),
                $this->snapshot(1, ['sub-owner' => 'member', 'sub-later' => 'admin'], 'Neu', '019a5e3c-0000-7000-8000-000000000004'),
            ]]],
            'GET households?sub=sub-member' => [200, ['data' => [$this->snapshot(2, name: 'Familie Meier')]]],
            // The owner left: the household no longer concerns this app's users → checked one by one.
            'GET households/'.$left->konto_household_uuid => [200, ['data' => $this->snapshot(2, ['sub-other' => 'admin'], 'Ferienhaus', $left->konto_household_uuid)]],
            'GET households/'.$gone->konto_household_uuid => [404, ['message' => 'Not found.']],
        ];

        $this->artisan('households:sync')->assertSuccessful();

        $this->assertSame('Familie Meier', $kept->fresh()->name);
        $this->assertSame(2, $kept->fresh()->konto_version);
        $this->assertFalse($left->hasUser($this->owner));
        $this->assertSame(['sub-other'], $left->pendingMembers()->pluck('oidc_sub')->all());
        $this->assertNull(Household::find($gone->id));
        $new = Household::where('konto_household_uuid', '019a5e3c-0000-7000-8000-000000000004')->firstOrFail();
        $this->assertSame(Household::MEMBER, $new->roleOf($this->owner));
        $this->assertSame(['sub-later'], $new->pendingMembers()->pluck('oidc_sub')->all());
        // Every household is fetched once.
        Http::assertSentCount(4);
    }

    public function test_the_last_state_stays_while_the_konto_is_down(): void
    {
        $household = $this->mirror($this->snapshot(1));
        $listId = $this->sharedList($this->owner, $household);
        $this->konto = ['GET households?sub=sub-owner' => fn () => Http::failedConnection(), 'GET households?sub=sub-member' => [503, []]];
        Log::spy();

        $this->artisan('households:sync')->assertFailed();

        $this->assertTrue($household->fresh()->hasUser($this->member));
        $this->assertSame($listId, $this->listDoc($this->member)['id']);
        $this->assertFalse($this->listDoc($this->member)['_deleted']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'households:sync'))->once();

        // An event of an unknown household cannot be confirmed: nothing is created, the job retries.
        $this->webhook('household.created', $this->snapshot(1, uuid: '019a5e3c-0000-7000-8000-000000000009'))->assertNoContent();
        $this->assertSame(1, Household::count());
    }

    public function test_local_households_are_proposed_and_mapped(): void
    {
        $local = User::factory()->create();
        // Mirrored already (the event came before the poll): the second proposal is merged into it.
        $mirrored = $this->mirror($this->snapshot(1, ['sub-owner' => 'admin'], 'Ferienhaus', '019a5e3c-0000-7000-8000-000000000002'));
        config(['households.driver' => 'local']);
        $family = Household::create(['name' => 'Familie', 'created_by' => $this->owner->id]);
        $family->addUser($this->owner, Household::ADMIN);
        $family->addUser($this->member);
        $family->addUser($local);
        $holiday = Household::create(['name' => 'Ferien']);
        $holiday->addUser($this->owner, Household::ADMIN);
        $nobody = Household::create(['name' => 'Ohne SSO']);
        $nobody->addUser($local, Household::ADMIN);
        $familyList = $this->sharedList($this->owner, $family);
        $holidayList = $this->sharedList($this->owner, $holiday, 'Ferien');
        $this->assertSame($familyList, $this->listDoc($local)['id']);
        config(['households.driver' => 'konto']);

        $this->konto['POST households/import'] = [202, ['data' => [
            ['local_id' => (string) $family->id, 'status' => 'pending', 'household_uuid' => null, 'household' => null],
            ['local_id' => (string) $holiday->id, 'status' => 'pending', 'household_uuid' => null, 'household' => null],
        ]]];
        $this->konto['GET households/import'] = [200, ['data' => [
            ['local_id' => (string) $family->id, 'status' => 'pending', 'household_uuid' => null, 'household' => null],
            ['local_id' => (string) $holiday->id, 'status' => 'pending', 'household_uuid' => null, 'household' => null],
        ]]];

        $this->artisan('households:import')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && $request->data() === ['households' => [
            ['local_id' => (string) $family->id, 'name' => 'Familie', 'members' => [['sub' => 'sub-owner', 'role' => 'admin'], ['sub' => 'sub-member', 'role' => 'member']]],
            ['local_id' => (string) $holiday->id, 'name' => 'Ferien', 'members' => [['sub' => 'sub-owner', 'role' => 'admin']]],
        ]]);
        $this->assertSame('pending', $family->fresh()->konto_import_status);
        $this->assertNull($nobody->fresh()->konto_import_status);
        $this->actingAs($this->owner)->get('/households')->assertOk()->assertSee('Proposed for your account, waiting for confirmation.');

        // The admin of the AccountManager confirmed both.
        $this->konto['GET households/import'] = [200, ['data' => [
            ['local_id' => (string) $family->id, 'status' => 'confirmed', 'household_uuid' => self::UUID, 'household' => $this->snapshot(1, name: 'Familie Muster')],
            ['local_id' => (string) $holiday->id, 'status' => 'confirmed', 'household_uuid' => $mirrored->konto_household_uuid, 'household' => $this->snapshot(2, ['sub-owner' => 'admin'], 'Ferienhaus', $mirrored->konto_household_uuid)],
        ]]];

        $this->artisan('households:import --check')->assertSuccessful();

        $family->refresh();
        $this->assertSame(self::UUID, $family->konto_household_uuid);
        $this->assertSame('Familie Muster', $family->name);
        $this->assertNull($family->konto_import_status);
        $this->assertTrue($family->hasUser($this->member));
        // Members without a linked login are not in the AccountManager's household.
        $this->assertFalse($family->hasUser($local));
        $this->assertTrue($this->listDoc($local)['_deleted']);
        $this->assertSame($familyList, $this->listDoc($this->member)['id']);

        $this->assertNull(Household::find($holiday->id));
        $this->assertSame([$holidayList], $mirrored->taskLists()->pluck('task_lists.id')->all());
        $this->assertSame(2, $mirrored->fresh()->konto_version);

        // Nothing pending anymore: the hourly check does not call the AccountManager.
        Http::fake(); // only resets the recorded requests
        $this->artisan('households:import --check')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_rejected_proposals_stay_local(): void
    {
        config(['households.driver' => 'local']);
        $family = Household::create(['name' => 'Familie']);
        $family->addUser($this->owner, Household::ADMIN);
        $listId = $this->sharedList($this->owner, $family);
        $family->addUser($this->member);
        config(['households.driver' => 'konto']);
        $family->forceFill(['konto_import_status' => 'pending'])->save();
        $this->konto['GET households/import'] = [200, ['data' => [
            ['local_id' => (string) $family->id, 'status' => 'rejected', 'household_uuid' => null, 'household' => null],
        ]]];

        $this->artisan('households:import --check')->assertSuccessful();

        $this->assertSame('rejected', $family->fresh()->konto_import_status);
        $this->assertNull($family->fresh()->konto_household_uuid);
        $this->assertSame($listId, $this->listDoc($this->member)['id']);
        $this->actingAs($this->owner)->get('/households')->assertOk()
            ->assertSee('Only in this app, not managed in your account.')
            ->assertSee('Not taken over into your account.');
    }

    public function test_shares_are_reported_to_the_konto(): void
    {
        config(['households.konto.report_shares' => true]);
        $household = $this->mirror($this->snapshot(1));
        $shares = [];
        $this->konto['PUT households/'.self::UUID.'/shares'] = function (HttpRequest $request) use (&$shares) {
            $shares[] = $request->data()['shares'];

            return Http::response(['data' => ['shares' => $request->data()['shares']]]);
        };

        $listId = $this->sharedList($this->owner, $household);
        $list = $this->listDoc($this->owner);
        $this->push($this->owner, 'lists', [['assumedMasterState' => $list, 'newDocumentState' => [...$list, 'name' => 'Haushalt', 'icon' => '🧹', 'version' => $list['version'] + 1]]])->assertExactJson([]);
        $this->actingAs($this->owner)->deleteJson("/lists/{$listId}/households/{$household->uuid}")->assertNoContent();

        $this->assertSame([
            [['resource_type' => 'task_list', 'resource_id' => $listId, 'label' => 'Familie']],
            [['resource_type' => 'task_list', 'resource_id' => $listId, 'label' => '🧹 Haushalt']],
            [],
        ], $shares);

        // The overview is optional: failures do not break sharing, the daily sync reports again.
        $this->konto['PUT households/'.self::UUID.'/shares'] = [500, ['message' => 'Server Error']];
        $this->actingAs($this->owner)->postJson("/lists/{$listId}/households", ['household' => $household->uuid])->assertOk();
        $this->assertTrue(TaskList::find($listId)->households()->whereKey($household->id)->exists());
        $this->assertSame(hash('sha256', '[]'), $household->fresh()->konto_shares_hash);
    }

    public function test_the_households_page_links_to_the_konto(): void
    {
        $this->mirror($this->snapshot(1, ['sub-owner' => 'admin', 'sub-new' => 'member', 'sub-other' => 'member']));

        $this->actingAs($this->owner)->get('/households')->assertOk()
            ->assertSee('Familie Muster')
            ->assertSee('https://konto.test/households')
            ->assertSee('2 more members have not used the app yet.')
            ->assertDontSee(route('households.store'));
        $this->actingAs($this->owner)->post('/households', ['name' => 'Neu'])->assertForbidden();
    }
}
