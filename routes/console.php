<?php

use App\Models\AccountManagerWebhookEvent;
use Illuminate\Support\Facades\Schedule;

// Production runs two cron jobs every minute (see README):
// `php artisan schedule:run` and `php artisan queue:work --max-time=50` (mails, push notifications).

Schedule::command('reminders:send')->everyFiveMinutes()->withoutOverlapping();

// HOUSEHOLDS_DRIVER=account-manager: fallback for lost webhooks, and the state of proposed local households.
Schedule::command('households:sync')->dailyAt('04:15')->withoutOverlapping();
Schedule::command('households:import --check')->hourly()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [AccountManagerWebhookEvent::class]])->daily();

// ENTITLEMENTS_DRIVER / AUTH_ADMIN_SOURCE=account-manager: fallback for lost entitlements.changed webhooks.
Schedule::command('entitlements:sync')->dailyAt('04:30')->withoutOverlapping();
