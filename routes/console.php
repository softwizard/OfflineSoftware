<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// S3-08. withoutOverlapping() is redundant with SyncRunner's own
// Cache::lock, but cheap insurance against the scheduler itself double
// -firing (e.g. a slow prior run still executing at the next tick).
Schedule::command('sync:run')->everyFiveMinutes()->withoutOverlapping();
