<?php

use App\Modules\OracleSync\Http\Controllers\OracleImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('oracle/imports')->name('oracle.imports.')->group(function () {
    Route::middleware('permission:oracle.import.view')->group(function () {
        Route::get('/', [OracleImportController::class, 'index'])->name('index');
        Route::get('{import}', [OracleImportController::class, 'show'])->whereNumber('import')->name('show');
    });
    Route::middleware('permission:oracle.import.run')->group(function () {
        Route::post('/', [OracleImportController::class, 'store'])->name('store');
        Route::post('{import}/parties/{code}', [OracleImportController::class, 'resolveParty'])->whereNumber(['import', 'code'])->name('resolve-party');
        Route::post('{import}/commit', [OracleImportController::class, 'commit'])->whereNumber('import')->name('commit');
        Route::post('{import}/discard', [OracleImportController::class, 'discard'])->whereNumber('import')->name('discard');
    });
});
