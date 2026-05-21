<?php

namespace App\Providers;

use App\Services\Cloudflare\CloudflareService;
use App\Services\Cloudflare\Contracts\CloudflareServiceInterface;
use App\Services\StormWall\Contracts\StormWallServiceInterface;
use App\Services\StormWall\StormWallService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            CloudflareServiceInterface::class,
            CloudflareService::class
        );

        $this->app->bind(
            StormWallServiceInterface::class,
            StormWallService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
    }
}
