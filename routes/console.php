<?php

use App\Jobs\DespacharActualizacionesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new DespacharActualizacionesJob('stock'), 'sync')
    ->everyThirtyMinutes()
    ->withoutOverlapping(10);

Schedule::job(new DespacharActualizacionesJob('precios'), 'sync')
    ->hourly()
    ->withoutOverlapping(30);

Schedule::job(new DespacharActualizacionesJob('promociones'), 'sync')
    ->twiceDaily(8, 20)
    ->withoutOverlapping(60);

Schedule::job(new DespacharActualizacionesJob('todo'), 'sync')
    ->dailyAt('02:00')
    ->withoutOverlapping(120);