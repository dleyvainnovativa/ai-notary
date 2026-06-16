<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tokens:reclaim')->everyFiveMinutes();
Schedule::command('documents:cleanup')->everyFiveMinutes();
// also prune failed jobs so extracted text doesn't linger (see validation notes)
Schedule::command('queue:prune-failed', ['--hours' => 24])->daily();
