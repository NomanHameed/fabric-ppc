<?php

use App\Modules\Production\Http\Controllers\InquiryController;
use App\Modules\Production\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// Read-only screens: the top-bar Lot/PO search, Lot Inquiry and PO Inquiry. Every seeded role has inquiry.view.
Route::middleware(['auth', 'verified', 'permission:inquiry.view'])->group(function () {
    Route::get('search', SearchController::class)->name('search');
    Route::prefix('inquiry')->name('inquiry.')->group(function () {
        Route::get('lots/{lot}', [InquiryController::class, 'lot'])->whereNumber('lot')->name('lot');
        Route::get('contracts/{contract}', [InquiryController::class, 'contract'])->whereNumber('contract')->name('contract');
    });
});
