<?php

use App\Modules\Production\Http\Controllers\IssueController;
use App\Modules\Production\Http\Controllers\LotRouteController;
use App\Modules\Production\Http\Controllers\LotStateController;
use Illuminate\Support\Facades\Route;

// One `production` group; later tasks add their controllers' routes inside it (there is no catch-all here).
Route::middleware(['auth', 'verified'])->prefix('production')->name('production.')->group(function () {
    Route::middleware('permission:production.issue')->group(function () {
        Route::get('issue', [IssueController::class, 'index'])->name('issue.index');
        Route::post('issue', [IssueController::class, 'store'])->name('issue.store');
        Route::post('issue/contract', [IssueController::class, 'storeContract'])->name('issue.contract');
    });

    Route::middleware('permission:production.route.manage')->group(function () {
        Route::get('routes', [LotRouteController::class, 'index'])->name('routes.index');
        Route::put('routes/{lot}', [LotRouteController::class, 'update'])->whereNumber('lot')->name('routes.update');
        Route::post('routes/{lot}/steps/{step}/skip', [LotRouteController::class, 'skip'])->whereNumber('lot')->whereNumber('step')->name('routes.skip');
        Route::post('routes/{lot}/apply', [LotRouteController::class, 'applyToLot'])->whereNumber('lot')->name('routes.apply_lot');
        Route::post('routes/contract/{contract}', [LotRouteController::class, 'applyToContract'])->whereNumber('contract')->name('routes.apply');
    });

    Route::middleware('permission:production.hold')->group(function () {
        Route::post('lots/{lot}/hold', [LotStateController::class, 'hold'])->whereNumber('lot')->name('lots.hold');
        Route::post('lots/{lot}/release', [LotStateController::class, 'release'])->whereNumber('lot')->name('lots.release');
        Route::post('lots/{lot}/cancel', [LotStateController::class, 'cancel'])->whereNumber('lot')->name('lots.cancel');
    });
});
