<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('lf:plans:roll')->hourly()->onOneServer()->withoutOverlapping();
Schedule::command('lf:accounts:purge')->dailyAt('03:15')->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('lf:recordings:purge')->dailyAt('03:40')->onOneServer();
