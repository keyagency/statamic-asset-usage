<?php

use Illuminate\Support\Facades\Route;
use KeyAgency\AssetUsage\Http\Controllers\AssetUsageController;
use KeyAgency\AssetUsage\Http\Controllers\CompressionController;
use KeyAgency\AssetUsage\Http\Controllers\ExportController;
use KeyAgency\AssetUsage\Http\Controllers\LogController;

/*
 * Each page has a URL of its own, and the before and after page sits under
 * the Compression one. Statamic marks a menu item active for its URL and
 * everything below it, so that is what keeps the right submenu item lit.
 */
Route::prefix('asset-usage')->name('asset-usage.')->group(function () {
    Route::get('/', fn () => redirect(cp_route('asset-usage.index')));
    Route::get('overview', [AssetUsageController::class, 'index'])->name('index');
    Route::get('compress', [AssetUsageController::class, 'compressionPage'])->name('compression');
    Route::get('log', [LogController::class, 'index'])->name('log');

    Route::get('assets', [AssetUsageController::class, 'assets'])->name('assets');
    Route::post('rebuild', [AssetUsageController::class, 'rebuild'])->name('rebuild');
    Route::get('status', [AssetUsageController::class, 'status'])->name('status');
    Route::delete('assets', [AssetUsageController::class, 'destroy'])->name('destroy');
    Route::delete('unused', [AssetUsageController::class, 'destroyUnused'])->name('destroy-unused');

    Route::prefix('export')->name('export.')->group(function () {
        Route::get('/', [ExportController::class, 'download'])->name('download');
        Route::get('thumbnails', [ExportController::class, 'thumbnails'])->name('thumbnails');
        Route::post('thumbnails', [ExportController::class, 'makeThumbnails'])->name('make-thumbnails');
    });

    Route::prefix('compress')->name('compress.')->group(function () {
        Route::get('compare', [CompressionController::class, 'show'])->name('show');
        Route::post('preview', [CompressionController::class, 'preview'])->name('preview');
        Route::get('image', [CompressionController::class, 'image'])->name('image');
        Route::post('/', [CompressionController::class, 'store'])->name('store');
        Route::post('restore', [CompressionController::class, 'restore'])->name('restore');
        Route::get('all', [AssetUsageController::class, 'compressible'])->name('all');
        Route::post('batch', [CompressionController::class, 'batch'])->name('batch');
        Route::post('analyze', [CompressionController::class, 'analyze'])->name('analyze');
        Route::get('status', [CompressionController::class, 'status'])->name('status');
    });
});
