<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('emails:alert-failures')->everyFiveMinutes();

if ((bool) config('backup.database.enabled', true)) {
    Schedule::command('backup:database')
        ->dailyAt((string) config('backup.database.schedule_at', '02:00'))
        ->withoutOverlapping()
        ->description('Gera backup diário do banco de dados');
}
