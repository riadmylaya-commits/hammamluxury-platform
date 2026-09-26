<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('hl:expire-waiting')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('hl:complete-past')->everyFifteenMinutes()->withoutOverlapping();
