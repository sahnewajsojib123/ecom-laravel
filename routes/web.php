<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ChattrnCommerceController;
use App\Http\Controllers\CommerceRulesController;
use App\Http\Controllers\CustomerAccountController;
use App\Http\Controllers\OrderOperationsController;
use App\Http\Controllers\StoreController;
use App\Http\Middleware\AdminOnly;
use App\Models\DraftOrder;
use App\Models\Order;
use Chattrn\Connector\Http\Middleware\VerifyChattrnToken;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::view('/', 'store');
Route::view('/admin', 'admin');
Route::view('/account', 'account');
Route::get('/orders/{order:reference}', fn (Order $order) => view('order-status', ['order' => $order]))->middleware('signed')->name('order.status');
Route::get('/cart/recover/{cart}', [CustomerAccountController::class, 'recover'])->middleware('signed')->name('cart.recover');
Route::prefix('api/v1')->middleware('throttle:store-api')->group(function () {
    Route::get('/storefront', [StoreController::class, 'bootstrap']);
    Route::get('/products', [StoreController::class, 'products']);
    Route::get('/products/{product}', [StoreController::class, 'product']);
    Route::post('/quote', [StoreController::class, 'quote']);
    Route::post('/account/code', [CustomerAccountController::class, 'requestCode'])->middleware('throttle:customer-auth');
    Route::post('/account/verify', [CustomerAccountController::class, 'verify'])->middleware('throttle:customer-auth');
    Route::get('/account', [CustomerAccountController::class, 'me']);
    Route::post('/account/logout', [CustomerAccountController::class, 'logout']);
    Route::post('/saved-cart', [CustomerAccountController::class, 'saveCart'])->middleware('throttle:checkout');
    Route::get('/saved-cart', [CustomerAccountController::class, 'recoveredCart']);
    Route::get('/orders/{order:reference}/manage', [OrderOperationsController::class, 'customerShow'])->name('order.customer.show');
    Route::post('/orders/{order:reference}/manage/{action}', [OrderOperationsController::class, 'customerAction'])->middleware('throttle:checkout')->name('order.customer.action');
    Route::post('/orders', [StoreController::class, 'order'])->middleware('throttle:checkout');
    Route::post('/admin/login', [AdminController::class, 'login'])->middleware('throttle:admin-login');
    Route::prefix('admin')->middleware(AdminOnly::class)->group(function () {
        Route::get('/drafts', fn () => DraftOrder::latest()->limit(100)->get());
        Route::post('/drafts', [ChattrnCommerceController::class, 'createDraftOrder']);
        Route::put('/drafts/{id}', [ChattrnCommerceController::class, 'updateDraftOrder']);
        Route::put('/drafts/{id}/complete', [ChattrnCommerceController::class, 'completeDraftOrder']);
        Route::get('/commerce', [CommerceRulesController::class, 'index']);
        Route::post('/commerce/rules/{type}', [CommerceRulesController::class, 'save']);
        Route::put('/commerce/rules/{type}/{id}', [CommerceRulesController::class, 'save']);
        Route::put('/commerce/payments', [CommerceRulesController::class, 'payments']);
        Route::post('/commerce/deliver', [CommerceRulesController::class, 'deliver']);
        Route::get('/orders/{order}', [OrderOperationsController::class, 'show']);
        Route::post('/orders/{order}/actions/{action}', [OrderOperationsController::class, 'action']);
        Route::get('/data', [AdminController::class, 'data']);
        Route::post('/logout', [AdminController::class, 'logout']);
        Route::put('/settings', [AdminController::class, 'settings']);
        Route::patch('/orders/{order}', [AdminController::class, 'status']);
        Route::post('/{type}', [AdminController::class, 'save']);
        Route::put('/{type}/{id}', [AdminController::class, 'save']);
    });
});

Route::prefix(ltrim(config('chattrn.route_prefix', '/chattrn/api/2026-10'), '/'))
    ->middleware(VerifyChattrnToken::class)
    ->withoutMiddleware(PreventRequestForgery::class)
    ->group(function () {
        Route::put('orders/{id}.json', [ChattrnCommerceController::class, 'updateOrder']);
        Route::post('orders/{id}/operations/{action}.json', [ChattrnCommerceController::class, 'operate']);
    });
