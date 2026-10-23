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
