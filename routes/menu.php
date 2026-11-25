<?php

use App\Modules\Branding\BrandingController;
use App\Modules\Categories\CategoryController;
use App\Modules\Media\MediaController;
use App\Modules\Products\ProductController;
use App\Modules\PublicMenus\MenuSettingsController;
use App\Shared\Http\JsonObject;
use Illuminate\Support\Facades\Route;

Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
Route::post('categories', [CategoryController::class, 'store'])->middleware(JsonObject::class)->name('categories.store');
Route::prefix('categories/{category}')->whereUuid('category')->group(function (): void {
    Route::get('/', [CategoryController::class, 'show'])->name('categories.show');
    Route::patch('/', [CategoryController::class, 'update'])->middleware(JsonObject::class)->name('categories.update');
    Route::delete('/', [CategoryController::class, 'destroy'])->name('categories.destroy');
});

Route::get('products', [ProductController::class, 'index'])->name('products.index');
Route::post('products', [ProductController::class, 'store'])->middleware(JsonObject::class)->name('products.store');
Route::prefix('products/{product}')->whereUuid('product')->group(function (): void {
    Route::get('/', [ProductController::class, 'show'])->name('products.show');
    Route::patch('/', [ProductController::class, 'update'])->middleware(JsonObject::class)->name('products.update');
    Route::patch('availability', [ProductController::class, 'update'])->middleware(JsonObject::class)->name('products.availability');
    Route::delete('/', [ProductController::class, 'destroy'])->name('products.destroy');
});

Route::get('media', [MediaController::class, 'index'])->name('media.index');
Route::post('media', [MediaController::class, 'store'])->middleware('throttle:emenu-media-upload')->name('media.store');
Route::prefix('media/{media}')->whereUuid('media')->group(function (): void {
    Route::get('/', [MediaController::class, 'show'])->name('media.show');
    Route::delete('/', [MediaController::class, 'destroy'])->name('media.destroy');
    Route::get('variants/{variant}', [MediaController::class, 'variant'])
        ->whereIn('variant', ['thumbnail', 'display'])->name('media.variant');
});

Route::get('branding', [BrandingController::class, 'show'])->name('branding.show');
Route::patch('branding', [BrandingController::class, 'update'])->middleware(JsonObject::class)->name('branding.update');

Route::get('menu-settings', [MenuSettingsController::class, 'show'])->name('menu-settings.show');
Route::patch('menu-settings', [MenuSettingsController::class, 'update'])->middleware(JsonObject::class)->name('menu-settings.update');
Route::post('publication', [MenuSettingsController::class, 'publish'])
    ->middleware([JsonObject::class, 'throttle:emenu-sensitive'])->name('publication.publish');
Route::delete('publication', [MenuSettingsController::class, 'unpublish'])->name('publication.unpublish');
Route::get('qr-code', [MenuSettingsController::class, 'qr'])->name('publication.qr');
