<?php

use App\Services\IntakeDrafts;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('kalinga:dispatch-deadline-alerts')->hourly()->withoutOverlapping();
Schedule::command('kalinga:expire-mdt-access')->hourly()->withoutOverlapping();
Schedule::command('kalinga:scan-pending-uploads --limit=100')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('kalinga:purge-intake-drafts', function () {
    $this->info(IntakeDrafts::purgeExpired() . ' expired saved intake(s) deleted.');
})->purpose('Deletes saved intakes not changed within the keeping period');
Schedule::command('kalinga:purge-intake-drafts')->dailyAt('01:15')->withoutOverlapping();
