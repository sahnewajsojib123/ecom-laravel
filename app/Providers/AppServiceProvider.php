<?php

namespace App\Providers;

use App\Http\Controllers\ChattrnCommerceController;
use Chattrn\Connector\Http\Controllers\ChattrnApiController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ChattrnApiController::class, ChattrnCommerceController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('store-api', fn (Request $request) => Limit::perMinute(120)->by('store-api:'.$request->ip()));
        RateLimiter::for('customer-auth', fn (Request $request) => Limit::perMinute(5)->by('customer-auth:'.$request->ip()));
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by('checkout:'.$request->ip()));
        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(5)->by('admin-login:'.$request->ip()));

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
