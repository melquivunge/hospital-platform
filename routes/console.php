<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Step 4c.4a (ADR-0007): hourly, so each unit hears once per day from 7 h in its time zone (idempotent per day);
// a run killed midway frees its lock after 10 minutes instead of a day.
Schedule::command('stock:alerts')->hourly()->withoutOverlapping(10);
// Step 5.3b (ADR-0008): orders without collection for 7 days, to the unit managers.
Schedule::command('exams:overdue')->hourly()->withoutOverlapping(10);
// Step 5.5: critical results without an acknowledgement for 1 hour reach the unit manager.
Schedule::command('exams:critical')->everyFiveMinutes()->withoutOverlapping(10);
