<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('emails:alert-failures')->everyFiveMinutes();
Schedule::command('account-payables:process-auto-payments')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->description('Baixa automaticamente parcelas a pagar vencendo no dia');

if ((bool) config('backup.database.enabled', true)) {
    Schedule::command('backup:database')
        ->dailyAt((string) config('backup.database.schedule_at', '02:00'))
        ->withoutOverlapping()
        ->description('Gera backup diário do banco de dados');
}

Schedule::command('sefaz:dfe-sync-dispatch')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->description('Despacha a sincronização assíncrona de DF-e recebidos por empresa');

if ((bool) config('audit.archive.enabled', true)) {
    Schedule::command('audit:archive-prune')
        ->dailyAt((string) config('audit.archive.schedule_at', '03:20'))
        ->withoutOverlapping()
        ->description('Arquiva auditorias antigas em JSONL e remove do banco principal');
}
