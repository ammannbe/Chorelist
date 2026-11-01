<?php

use Illuminate\Support\Facades\Schedule;

// Production runs two cron jobs every minute (see README):
// `php artisan schedule:run` and `php artisan queue:work --max-time=50` (mails, push notifications).

Schedule::command('reminders:send')->everyFiveMinutes()->withoutOverlapping();
