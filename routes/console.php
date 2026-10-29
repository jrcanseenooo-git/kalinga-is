<?php

use App\Services\IntakeDrafts;
use App\Services\UatCredentialDelivery;
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

Artisan::command('kalinga:purge-uat-delivery', function () {
    $this->info(UatCredentialDelivery::purgeExpired() . ' expired UAT delivery export(s) deleted.');
})->purpose('Deletes expired encrypted UAT credential delivery exports');
Schedule::command('kalinga:purge-uat-delivery')->everyMinute()->withoutOverlapping();
