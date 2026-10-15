<?php

use App\Modules\Foundation\Http\Controllers\Admin\RoleController;
use App\Modules\Foundation\Http\Controllers\Admin\SettingsController;
use App\Modules\Foundation\Http\Controllers\Admin\UserApprovalController;
use App\Modules\Foundation\Http\Controllers\Admin\UserController;
use App\Modules\Foundation\Http\Controllers\ApprovalPendingController;
use App\Modules\Foundation\Http\Controllers\LocaleController;
use App\Modules\Foundation\Http\Controllers\PartyController;
use App\Modules\Foundation\Http\Controllers\RouteTemplateController;
use App\Modules\Foundation\Http\Controllers\StageController;
use App\Modules\Foundation\Http\Controllers\StageProcessController;
use App\Modules\Foundation\Http\Controllers\TranslationController;
use App\Modules\Foundation\Lov\LovController;
use App\Modules\Foundation\Masters\MasterController;
use Illuminate\Support\Facades\Route;

Route::get('i18n/{locale}.json', [TranslationController::class, 'show'])
    ->where('locale', '[a-z]{2}')->name('i18n.show');

// The one screen an unapproved account may reach (EnsureUserIsApproved::ALLOWED_ROUTES). Not `verified`:
// a user who has not confirmed their email yet still needs to be told what they are waiting for.
Route::get('approval/pending', ApprovalPendingController::class)->middleware('auth')->name('approval.pending');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('locale', [LocaleController::class, 'update'])->name('locale.update');
    Route::get('lov/{name}', [LovController::class, 'show'])->name('lov.show');

    // The stage / stage process read API (spec §16), for StageSelect and StageProcessSelect. Under the web
    // middleware group on purpose: this application authenticates with the session, so a stateless `api`
    // route group would simply be unauthenticated. `api/*` already renders errors as JSON (bootstrap/app.php).
    // `masters.view` because stages are master data, and every seeded role that can reach a screen showing a
    // lot's stage holds it.
    Route::middleware('permission:masters.view')->prefix('api')->name('api.')->group(function () {
        Route::get('stages', [StageController::class, 'index'])->name('stages.index');
        // Explicit, above `stages/{stage}`: `{stage}` is numeric-constrained, so there is no ambiguity.
        Route::get('stages/{stage}/processes', [StageController::class, 'processes'])->whereNumber('stage')->name('stages.processes');
        Route::get('stages/{stage}', [StageController::class, 'show'])->whereNumber('stage')->name('stages.show');
        Route::get('stage-processes/{stageProcess}', [StageProcessController::class, 'show'])
            ->whereNumber('stageProcess')->name('stage_processes.show');
    });

    Route::prefix('masters')->name('masters.')->group(function () {
        Route::get('parties', [PartyController::class, 'index'])->name('parties.index');
        Route::post('parties', [PartyController::class, 'store'])->name('parties.store');
        Route::put('parties/{party}', [PartyController::class, 'update'])->name('parties.update');
        Route::delete('parties/{party}', [PartyController::class, 'destroy'])->name('parties.destroy');

        Route::get('route-templates', [RouteTemplateController::class, 'index'])->name('route-templates.index');
        Route::post('route-templates', [RouteTemplateController::class, 'store'])->name('route-templates.store');
        Route::put('route-templates/{routeTemplate}', [RouteTemplateController::class, 'update'])->name('route-templates.update');
        Route::delete('route-templates/{routeTemplate}', [RouteTemplateController::class, 'destroy'])->name('route-templates.destroy');
    });

    Route::controller(MasterController::class)->prefix('masters')->name('masters.')->group(function () {
        Route::get('{resource}', 'index')->name('index');
        Route::post('{resource}', 'store')->name('store');
        Route::put('{resource}/{id}', 'update')->whereNumber('id')->name('update');
        Route::delete('{resource}/{id}', 'destroy')->whereNumber('id')->name('destroy');
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        // Explicit before the generic {user} routes below.
        Route::middleware('permission:admin.users.approve')->group(function () {
            Route::get('users/approvals', [UserApprovalController::class, 'index'])->name('users.approvals.index');
            Route::post('users/{user}/approve', [UserApprovalController::class, 'approve'])->name('users.approve');
            Route::post('users/{user}/reject', [UserApprovalController::class, 'reject'])->name('users.reject');
        });
        Route::middleware('permission:admin.users.manage')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        });
        Route::middleware('permission:admin.roles.manage')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        });
        Route::middleware('permission:admin.settings.manage')->group(function () {
            Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
        });
    });
});
