<?php

use Illuminate\Support\Facades\Route;
use KeyAgency\AssetUsage\Http\Controllers\AssetUsageController;

Route::prefix('asset-usage')->name('asset-usage.')->group(function () {
    Route::get('/', [AssetUsageController::class, 'index'])->name('index');
    Route::get('assets', [AssetUsageController::class, 'assets'])->name('assets');
    Route::post('rebuild', [AssetUsageController::class, 'rebuild'])->name('rebuild');
    Route::get('status', [AssetUsageController::class, 'status'])->name('status');
    Route::delete('assets', [AssetUsageController::class, 'destroy'])->name('destroy');
    Route::delete('unused', [AssetUsageController::class, 'destroyUnused'])->name('destroy-unused');
});
