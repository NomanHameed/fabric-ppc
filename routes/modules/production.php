<?php

use App\Modules\Production\Http\Controllers\LotStateController;
use Illuminate\Support\Facades\Route;

// One `production` group; later tasks add their controllers' routes inside it (there is no catch-all here).
Route::middleware(['auth', 'verified'])->prefix('production')->name('production.')->group(function () {
    Route::middleware('permission:production.hold')->group(function () {
        Route::post('lots/{lot}/hold', [LotStateController::class, 'hold'])->whereNumber('lot')->name('lots.hold');
        Route::post('lots/{lot}/release', [LotStateController::class, 'release'])->whereNumber('lot')->name('lots.release');
        Route::post('lots/{lot}/cancel', [LotStateController::class, 'cancel'])->whereNumber('lot')->name('lots.cancel');
    });
});
