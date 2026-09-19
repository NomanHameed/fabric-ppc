<?php

use Illuminate\Support\Facades\Route;

Route::get('i18n/{locale}.json', [\App\Modules\Foundation\Http\Controllers\TranslationController::class, 'show'])
    ->where('locale', '[a-z]{2}')->name('i18n.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('locale', [\App\Modules\Foundation\Http\Controllers\LocaleController::class, 'update'])->name('locale.update');
    Route::get('lov/{name}', [\App\Modules\Foundation\Lov\LovController::class, 'show'])->name('lov.show');

    Route::controller(\App\Modules\Foundation\Masters\MasterController::class)->prefix('masters')->name('masters.')->group(function () {
        Route::get('{resource}', 'index')->name('index');
        Route::post('{resource}', 'store')->name('store');
        Route::put('{resource}/{id}', 'update')->whereNumber('id')->name('update');
        Route::delete('{resource}/{id}', 'destroy')->whereNumber('id')->name('destroy');
    });
});
