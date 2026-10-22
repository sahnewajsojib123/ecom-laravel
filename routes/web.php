<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{StoreController,AdminController};
use App\Http\Middleware\AdminOnly;
Route::view('/','store');
Route::view('/admin','admin');
Route::prefix('api/v1')->middleware('throttle:120,1')->group(function(){
 Route::get('/storefront',[StoreController::class,'bootstrap']);
 Route::get('/products',[StoreController::class,'products']);
 Route::get('/products/{product}',[StoreController::class,'product']);
 Route::post('/orders',[StoreController::class,'order'])->middleware('throttle:10,1');
 Route::post('/admin/login',[AdminController::class,'login'])->middleware('throttle:5,1');
 Route::prefix('admin')->middleware(AdminOnly::class)->group(function(){
 Route::get('/data',[AdminController::class,'data']);
 Route::post('/logout',[AdminController::class,'logout']);
 Route::put('/settings',[AdminController::class,'settings']);
 Route::patch('/orders/{order}',[AdminController::class,'status']);
 Route::post('/{type}',[AdminController::class,'save']);
 Route::put('/{type}/{id}',[AdminController::class,'save']);
 });
});
