<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('hl:expire-waiting')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('hl:complete-past')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('hl:cancellation-reminders')->hourly()->withoutOverlapping();
Schedule::command('hl:review-invitations')->dailyAt('10:00')->withoutOverlapping();
