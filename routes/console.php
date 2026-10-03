<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule (OB-6, ANTI-6 O8)
|--------------------------------------------------------------------------
|
| Driven in production by one systemd timer running `schedule:run` every
| minute (deploy/systemd/purrenade-scheduler.{service,timer}).
|
| The replay-input sweeper is the only entry. Analytics rollups, security and
| audit purges or health sampling are NOT scheduled here merely because a
| scheduler now exists: each needs its own decision first.
|
*/

Schedule::command('replay:sweep')->everyMinute()->withoutOverlapping(5);
