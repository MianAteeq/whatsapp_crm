<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule Social Media Planner automated publishing
Schedule::command('social:publish-scheduled')->everyMinute();
Schedule::command('email:process-scheduled')->everyMinute();
