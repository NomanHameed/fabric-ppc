<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', \App\Modules\Foundation\Http\Controllers\DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';

foreach (glob(__DIR__.'/modules/*.php') as $moduleRoutes) {
    require $moduleRoutes;
}
