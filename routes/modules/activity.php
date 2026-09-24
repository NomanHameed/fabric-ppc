<?php

use App\Modules\Activity\Http\Controllers\DeviceController;
use App\Modules\Activity\Http\Controllers\UserActionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // The device is whatever the signed cookie resolved to; only its name is submitted.
    // Explicit segments first, so `devices/current` is never read as a parameter of a later generic route.
    Route::get('devices/current', [DeviceController::class, 'current'])->name('devices.current');
    Route::put('devices/name', [DeviceController::class, 'update'])->name('devices.name');
    Route::get('devices', [DeviceController::class, 'edit'])->name('devices.edit');

    Route::get('activity/actions', [UserActionController::class, 'index'])
        ->middleware('permission:activity.view')->name('activity.actions.index');
});
