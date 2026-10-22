<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\StoreController;
use App\Http\Middleware\AdminOnly;
use Illuminate\Support\Facades\Route;

Route::view('/', 'store');
Route::view('/admin', 'admin');
Route::prefix('api/v1')->middleware('throttle:store-api')->group(function () {
    Route::get('/storefront', [StoreController::class, 'bootstrap']);
    Route::get('/products', [StoreController::class, 'products']);
    Route::get('/products/{product}', [StoreController::class, 'product']);
    Route::post('/orders', [StoreController::class, 'order'])->middleware('throttle:checkout');
    Route::post('/admin/login', [AdminController::class, 'login'])->middleware('throttle:admin-login');
    Route::prefix('admin')->middleware(AdminOnly::class)->group(function () {
        Route::get('/data', [AdminController::class, 'data']);
        Route::post('/logout', [AdminController::class, 'logout']);
        Route::put('/settings', [AdminController::class, 'settings']);
        Route::patch('/orders/{order}', [AdminController::class, 'status']);
        Route::post('/{type}', [AdminController::class, 'save']);
        Route::put('/{type}/{id}', [AdminController::class, 'save']);
    });
});
