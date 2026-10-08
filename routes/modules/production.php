<?php

use App\Modules\Production\Http\Controllers\ContractController;
use App\Modules\Production\Http\Controllers\FabricReportController;
use App\Modules\Production\Http\Controllers\IssueController;
use App\Modules\Production\Http\Controllers\LotController;
use App\Modules\Production\Http\Controllers\LotRouteController;
use App\Modules\Production\Http\Controllers\LotStateController;
use App\Modules\Production\Http\Controllers\LotTimelineController;
use App\Modules\Production\Http\Controllers\LotTimelineScreenController;
use App\Modules\Production\Http\Controllers\MachineUtilisationController;
use App\Modules\Production\Http\Controllers\ProductionAnalyticsController;
use App\Modules\Production\Http\Controllers\ProductionSheetController;
use App\Modules\Production\Http\Controllers\UnmappedStatusController;
use Illuminate\Support\Facades\Route;

// One `production` group; later tasks add their controllers' routes inside it (there is no catch-all here).
Route::middleware(['auth', 'verified'])->prefix('production')->name('production.')->group(function () {
    // The Lots and POs screens: every seeded role has inquiry.view. `PUT lots/{lot}` is guarded further inside
    // LotController (colour.edit / production.route.manage). Registered above the `lots/{lot}/…` state routes.
    Route::middleware('permission:inquiry.view')->group(function () {
        Route::get('lots', [LotController::class, 'index'])->name('lots.index');
        // Explicit, above `lots/{lot}` and the state routes below.
        Route::get('lots/{lot}/timeline', [LotTimelineController::class, 'show'])->whereNumber('lot')->name('lots.timeline');
        // The screen that draws that JSON; `timeline` is a fixed segment, so it cannot be read as a lot id.
        Route::get('timeline', [LotTimelineScreenController::class, 'index'])->name('timeline.index');
        // Dashboard charts. inquiry.view is the widest production read every role holds; the capacity half of
        // the payload is dropped inside the controller for anyone without production.sheet.view.
        Route::get('analytics', [ProductionAnalyticsController::class, 'index'])->name('analytics.index');
        // The fabric report's stage -> stage process figures, with `?shape=flat` listing each process's lots
        // in the flat Oracle-shaped payload. A read of the same lots the Lots screen shows, so it follows the
        // same permission.
        Route::get('fabric-report', [FabricReportController::class, 'index'])->name('fabric_report.index');
        Route::put('lots/{lot}', [LotController::class, 'update'])->whereNumber('lot')->name('lots.update');
        Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
    });

    Route::middleware('permission:production.issue')->group(function () {
        Route::get('issue', [IssueController::class, 'index'])->name('issue.index');
        Route::post('issue', [IssueController::class, 'store'])->name('issue.store');
        Route::post('issue/contract', [IssueController::class, 'storeContract'])->name('issue.contract');
    });

    Route::middleware('permission:production.route.manage')->group(function () {
        // The manual stage override on the Lots screen. The controller checks the same permission again
        // before it validates anything, so the 403 does not depend on this group alone.
        Route::put('lots/{lot}/stage', [LotController::class, 'overrideStage'])->whereNumber('lot')->name('lots.stage.override');
        Route::delete('lots/{lot}/stage', [LotController::class, 'clearStageOverride'])->whereNumber('lot')->name('lots.stage.clear');

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

    // Unmapped statuses: a master-data gap report whose only remedy is adding an alias on the Stage
    // Processes master, so it follows that master's edit permission rather than a read one.
    Route::middleware('permission:masters.manage')->group(function () {
        Route::get('unmapped-statuses', [UnmappedStatusController::class, 'index'])->name('unmapped_statuses.index');
        // Mapping a status from the report itself: the alias is added to the process and the lots on that
        // status are re-resolved. The controller re-checks the permission before it validates anything.
        Route::post('unmapped-statuses/{stageProcess}/aliases', [UnmappedStatusController::class, 'map'])
            ->whereNumber('stageProcess')->name('unmapped_statuses.map');
    });

    // Capacity/utilisation: a planning read, so it follows the sheet-view permission every floor role has.
    Route::middleware('permission:production.sheet.view')->group(function () {
        Route::get('capacity', [MachineUtilisationController::class, 'screen'])->name('capacity.index');
        Route::get('utilisation', [MachineUtilisationController::class, 'index'])->name('utilisation.index');
    });

    // Viewing needs production.sheet.view; the writes are authorised per department by ProductionSheetPolicy.
    // Explicit paths (split-batch, lot-context) sit above the parameterised {sheet} routes.
    Route::middleware('permission:production.sheet.view')->group(function () {
        Route::get('sheets', [ProductionSheetController::class, 'index'])->name('sheets.index');
        Route::post('sheets/split-batch', [ProductionSheetController::class, 'splitBatch'])->name('sheets.split');
        Route::get('sheets/lot-context/{lot}', [ProductionSheetController::class, 'lotContext'])->whereNumber('lot')->name('sheets.lot-context');
        Route::post('sheets', [ProductionSheetController::class, 'store'])->name('sheets.store');
        Route::get('sheets/{sheet}/totals', [ProductionSheetController::class, 'totals'])->whereNumber('sheet')->name('sheets.totals');
        Route::put('sheets/{sheet}', [ProductionSheetController::class, 'update'])->whereNumber('sheet')->name('sheets.update');
        Route::delete('sheets/{sheet}', [ProductionSheetController::class, 'destroy'])->whereNumber('sheet')->name('sheets.destroy');
    });
});
