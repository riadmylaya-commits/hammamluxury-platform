<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('hl:expire-waiting')->everyFiveMinutes()->withoutOverlapping();
