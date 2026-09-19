<?php

use Illuminate\Support\Facades\Route;

Route::get('i18n/{locale}.json', [\App\Modules\Foundation\Http\Controllers\TranslationController::class, 'show'])
    ->where('locale', '[a-z]{2}')->name('i18n.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('locale', [\App\Modules\Foundation\Http\Controllers\LocaleController::class, 'update'])->name('locale.update');
    Route::get('lov/{name}', [\App\Modules\Foundation\Lov\LovController::class, 'show'])->name('lov.show');

    Route::prefix('masters')->name('masters.')->group(function () {
        Route::get('parties', [\App\Modules\Foundation\Http\Controllers\PartyController::class, 'index'])->name('parties.index');
        Route::post('parties', [\App\Modules\Foundation\Http\Controllers\PartyController::class, 'store'])->name('parties.store');
        Route::put('parties/{party}', [\App\Modules\Foundation\Http\Controllers\PartyController::class, 'update'])->name('parties.update');
        Route::delete('parties/{party}', [\App\Modules\Foundation\Http\Controllers\PartyController::class, 'destroy'])->name('parties.destroy');

        Route::get('route-templates', [\App\Modules\Foundation\Http\Controllers\RouteTemplateController::class, 'index'])->name('route-templates.index');
        Route::post('route-templates', [\App\Modules\Foundation\Http\Controllers\RouteTemplateController::class, 'store'])->name('route-templates.store');
        Route::put('route-templates/{routeTemplate}', [\App\Modules\Foundation\Http\Controllers\RouteTemplateController::class, 'update'])->name('route-templates.update');
        Route::delete('route-templates/{routeTemplate}', [\App\Modules\Foundation\Http\Controllers\RouteTemplateController::class, 'destroy'])->name('route-templates.destroy');
    });

    Route::controller(\App\Modules\Foundation\Masters\MasterController::class)->prefix('masters')->name('masters.')->group(function () {
        Route::get('{resource}', 'index')->name('index');
        Route::post('{resource}', 'store')->name('store');
        Route::put('{resource}/{id}', 'update')->whereNumber('id')->name('update');
        Route::delete('{resource}/{id}', 'destroy')->whereNumber('id')->name('destroy');
    });
});
