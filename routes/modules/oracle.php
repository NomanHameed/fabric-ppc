<?php

use App\Modules\OracleSync\Http\Controllers\OracleImportController;
use App\Modules\OracleSync\Http\Controllers\OracleSourceController;
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

/*
 * The Oracle source schema browser (read-only). Its own prefix and name group, gated on a permission of
 * its own: this is the only screen that reaches into the client's live ERP, so seeing it is not implied by
 * being able to run an import. The explicit index sits above the two-segment owner/object route.
 */
Route::middleware(['auth', 'verified', 'permission:oracle.source.browse'])
    ->prefix('oracle/source')
    ->name('oracle.source.')
    ->group(function () {
        Route::get('/', [OracleSourceController::class, 'index'])->name('index');
        Route::get('{owner}/{object}', [OracleSourceController::class, 'show'])
            ->where(['owner' => '[A-Za-z0-9_$#]{1,128}', 'object' => '[A-Za-z0-9_$#]{1,128}'])
            ->name('show');
    });
