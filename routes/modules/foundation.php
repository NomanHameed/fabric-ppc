<?php

use Illuminate\Support\Facades\Route;

Route::get('i18n/{locale}.json', [\App\Modules\Foundation\Http\Controllers\TranslationController::class, 'show'])
    ->where('locale', '[a-z]{2}')->name('i18n.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('locale', [\App\Modules\Foundation\Http\Controllers\LocaleController::class, 'update'])->name('locale.update');
});
