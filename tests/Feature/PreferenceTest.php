<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_returned_until_changed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/me')
            ->assertJsonPath('preferences.row_actions', 'swipe')
            ->assertJsonPath('preferences.notifications.reminder', ['push'])
            ->assertJsonPath('preferences.reminder_time', '08:00');

        $this->actingAs($user)->putJson('/preferences', ['row_actions' => 'longpress', 'theme' => 'dark'])->assertNoContent();

        $this->actingAs($user->fresh())->getJson('/me')
            ->assertJsonPath('preferences.row_actions', 'longpress')
            ->assertJsonPath('preferences.theme', 'dark');
    }

    public function test_notification_channels_per_kind(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/preferences', ['notifications' => ['completed' => ['mail', 'push', 'mail']]])->assertNoContent();
        $this->actingAs($user)->putJson('/preferences', ['notifications' => ['reminder' => []]])->assertNoContent();

        $user->refresh();
        $this->assertSame(['mail', 'push'], $user->channelsFor('completed'));
        $this->assertSame([], $user->channelsFor('reminder'));
        $this->assertSame(['push'], $user->channelsFor('overdue'));

        $this->actingAs($user)->putJson('/preferences', ['notifications' => ['completed' => ['sms']]])->assertUnprocessable();
        $this->actingAs($user)->putJson('/preferences', ['notifications' => ['unknown' => ['mail']]])->assertUnprocessable();
    }

    public function test_time_zone_and_reminder_time(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/preferences', ['timezone' => 'Europe/Zurich', 'reminder_time' => '07:30'])->assertNoContent();
        $this->actingAs($user)->putJson('/preferences', ['timezone' => 'Mars/Base'])->assertUnprocessable();
        $this->actingAs($user)->putJson('/preferences', ['reminder_time' => '25:00'])->assertUnprocessable();

        $this->actingAs($user->fresh())->getJson('/me')
            ->assertJsonPath('timezone', 'Europe/Zurich')
            ->assertJsonPath('preferences.reminder_time', '07:30');
    }

    public function test_push_subscriptions_are_stored_per_device(): void
    {
        $user = User::factory()->create();
        $subscription = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'key', 'auth' => 'secret']];

        $this->actingAs($user)->postJson('/push-subscriptions', $subscription)->assertNoContent();
        $this->actingAs($user)->postJson('/push-subscriptions', $subscription)->assertNoContent();
        $this->actingAs($user)->getJson('/me')->assertJsonPath('push_devices', 1);

        $this->actingAs($user)->deleteJson('/push-subscriptions', ['endpoint' => $subscription['endpoint']])->assertNoContent();
        $this->assertSame(0, $user->pushSubscriptions()->count());
    }

    public function test_push_endpoints_must_belong_to_a_push_service(): void
    {
        $user = User::factory()->create();
        $keys = ['p256dh' => 'key', 'auth' => 'secret'];

        foreach (['https://web.push.apple.com/abc', 'https://wns2-par02p.notify.windows.com/w/?token=abc'] as $endpoint) {
            $this->actingAs($user)->postJson('/push-subscriptions', ['endpoint' => $endpoint, 'keys' => $keys])->assertNoContent();
        }
        foreach ([
            'https://push.example.com/abc', 'https://169.254.169.254/latest', 'https://fcm.googleapis.com:8443/x',
            'https://evil.com/fcm.googleapis.com', 'https://user@fcm.googleapis.com/x', 'https://push.apple.com.evil.com/x',
        ] as $endpoint) {
            $this->actingAs($user)->postJson('/push-subscriptions', ['endpoint' => $endpoint, 'keys' => $keys])
                ->assertJsonValidationErrors('endpoint');
        }
        $this->assertSame(2, $user->pushSubscriptions()->count());
    }
}
