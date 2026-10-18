<?php

use App\Console\Commands\NipoFollowUpCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('nipo:follow-up')
    ->cron(NipoFollowUpCommand::expressionFor((int) config('blitto.nipo_follow_up_interval_hours')))
    ->withoutOverlapping();
