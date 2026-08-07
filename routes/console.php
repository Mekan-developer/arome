<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Дамп базы 1-го и 16-го числа в 03:00 — ближайший к «раз в 15 дней» вариант из
 * встроенных. withoutOverlapping страхует от наложения, если предыдущий дамп
 * ещё идёт.
 */
Schedule::command('db:backup')
    ->twiceMonthly(1, 16, '03:00')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Бэкап БД провалился'));
