<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\TaskList;
use App\Models\User;
use App\Notifications\ListShared;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberTest extends TestCase
{
    use RefreshDatabase;

    private function listOf(User $owner): TaskList
    {
        $list = new TaskList(['name' => 'WG']);
        $list->id = (string) Str::uuid();
        $list->owner()->associate($owner)->save();
        $list->addMember($owner);

        return $list;
    }

    public function test_existing_users_are_invited_and_join_after_confirming(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['name' => 'Ben']);
        $member = User::factory()->create();
        $list = $this->listOf($owner);

        $this->actingAs($owner)->postJson("/lists/{$list->id}/members", ['email' => strtoupper($member->email)])
            ->assertOk()->assertJson(['status' => 'invited']);

        $this->assertFalse($list->hasMember($member));
        $path = null;
        Notification::assertSentTo($member, ListShared::class, function ($notification) use ($member, &$path) {
            $path = parse_url($notification->toMail($member)->actionUrl, PHP_URL_PATH);

            return in_array('mail', $notification->via($member), true);
        });

        // The invitation is only for that user, and opening it does not join yet.
        $this->actingAs(User::factory()->create())->get($path)->assertRedirect('/#/invite-invalid');
        $this->actingAs($member)->get($path)->assertOk()->assertSee('Ben')->assertSee('WG');
        $this->assertFalse($list->hasMember($member));

        $this->actingAs($member)->post($path)->assertRedirect("/#/list/{$list->id}");
        $this->assertTrue($list->hasMember($member));
        // Used up.
        $this->actingAs($member)->post($path)->assertRedirect('/#/invite-invalid');

        $this->actingAs($owner)->postJson("/lists/{$list->id}/members", ['email' => $member->email])
            ->assertOk()->assertJson(['status' => 'member']);
    }

    public function test_unknown_users_are_invited(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $list = $this->listOf($owner);

        $this->actingAs($owner)->postJson("/lists/{$list->id}/members", ['email' => 'neu@example.com'])
            ->assertOk()->assertJson(['status' => 'added']);

        $invited = User::firstWhere('email', 'neu@example.com');
        $this->assertNull($invited->email_verified_at);
        $this->assertTrue($list->hasMember($invited));
        Notification::assertSentTo($invited, ListShared::class, function ($notification) use ($invited) {
            return str_contains($notification->toMail($invited)->actionUrl, '/reset-password/');
        });
    }

    public function test_only_owners_share_lists(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $list = $this->listOf($owner);
        $list->addMember($member);

        $this->actingAs($member)->postJson("/lists/{$list->id}/members", ['email' => 'x@example.com'])
            ->assertForbidden();
    }

    public function test_owners_remove_members_and_members_leave(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $other = User::factory()->create();
        $list = $this->listOf($owner);
        $list->addMember($member);
        $list->addMember($other);

        $this->actingAs($member)->deleteJson("/lists/{$list->id}/members/{$other->email}")->assertForbidden();
        $this->actingAs($member)->deleteJson("/lists/{$list->id}/members/{$owner->email}")->assertForbidden();

        $this->actingAs($owner)->deleteJson("/lists/{$list->id}/members/{$other->email}")->assertNoContent();
        $this->actingAs($member)->deleteJson("/lists/{$list->id}/members/{$member->email}")->assertNoContent();
        $this->actingAs($owner)->deleteJson("/lists/{$list->id}/members/{$owner->email}")->assertStatus(422);

        $this->assertFalse($list->hasMember($member));
        $this->assertFalse($list->hasMember($other));
        $this->assertTrue($list->hasMember($owner));
    }

    public function test_invitation_links_add_whoever_confirms_them(): void
    {
        $owner = User::factory()->create();
        $guest = User::factory()->unverified()->create();
        $list = $this->listOf($owner);

        $this->actingAs($guest)->postJson("/lists/{$list->id}/invites")->assertForbidden();
        $url = $this->actingAs($owner)->postJson("/lists/{$list->id}/invites")->assertOk()->json('url');
        $path = parse_url($url, PHP_URL_PATH);

        // Logged out: login first, then back to the link.
        auth()->logout();
        $this->get($path)->assertRedirect('/login');
        // Opening shows the list and asks, joining needs the confirmation.
        $this->actingAs($guest)->get($path)->assertOk()->assertSee('WG');
        $this->assertFalse($list->hasMember($guest));
        $this->actingAs($guest)->post($path)->assertRedirect("/#/list/{$list->id}");
        $this->assertTrue($list->hasMember($guest));
        $this->actingAs($guest)->get($path)->assertRedirect("/#/list/{$list->id}");

        // Revoked or expired links do nothing.
        $this->actingAs($owner)->deleteJson("/lists/{$list->id}/invites")->assertNoContent();
        $other = User::factory()->create();
        $this->actingAs($other)->get($path)->assertRedirect('/#/invite-invalid');
        $this->assertFalse($list->hasMember($other));
        $this->assertDatabaseMissing('list_invites', ['token' => basename($path)]);
    }

    public function test_sharing_is_throttled_and_does_not_reveal_accounts(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $list = $this->listOf($owner);

        $this->actingAs($stranger)->deleteJson("/lists/{$list->id}/members/nobody@example.com")->assertForbidden();
        $this->actingAs($stranger)->deleteJson("/lists/{$list->id}/members/{$owner->email}")->assertForbidden();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($owner)->postJson("/lists/{$list->id}/invites")->assertOk();
        }
        $this->actingAs($owner)->postJson("/lists/{$list->id}/invites")->assertTooManyRequests();
    }

    public function test_reset_password_verifies_invited_users(): void
    {
        $user = User::factory()->unverified()->create();
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_accounts_registered_by_somebody_else_are_taken_over_by_the_invited_user(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $list = $this->listOf($owner);
        // Somebody registered the address before, without verifying it.
        $victim = User::factory()->unverified()->create(['email' => 'anna@example.com']);
        $victim->passkeys()->create(['name' => 'Fremd', 'credential_id' => 'abc', 'credential' => []]);

        $this->actingAs($owner)->postJson("/lists/{$list->id}/members", ['email' => $victim->email])->assertOk();
        Notification::assertSentTo($victim, ListShared::class, function ($notification) use ($victim) {
            return str_contains($notification->toMail($victim)->actionUrl, '/reset-password/');
        });

        $this->flushSession();
        $this->actingAs($victim)->getJson('/me')->assertOk();
        $this->actingAs($victim)->getJson('/me')->assertOk();

        app(ResetUserPassword::class)->reset($victim, ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123']);

        $this->assertSame(0, $victim->passkeys()->count());
        $this->assertNotNull($victim->fresh()->email_verified_at);
        // The session of whoever registered the address ends.
        $this->actingAs($victim)->getJson('/me')->assertUnauthorized();
    }
}
