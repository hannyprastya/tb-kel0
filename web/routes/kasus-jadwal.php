<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Jadwal Kasus 0 (dimuat routes/console.php). Lihat: php artisan schedule:list
// Setiap hari 07.00 WIB (zona APP_TIMEZONE): titip pengingat untuk
// peminjaman yang jatuh tempo paling lambat besok; worker yang mengirim.
Schedule::call(fn () => Artisan::call('kasus:pengingat-jatuh-tempo'))
    ->name('kasus:pengingat-jatuh-tempo')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->onOneServer();
