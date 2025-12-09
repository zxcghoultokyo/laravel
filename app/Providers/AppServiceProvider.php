<?php

namespace App\Providers;

use App\Services\Horoshop\HoroshopClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(HoroshopClient::class, function () {
            return new HoroshopClient(
                domain: config('services.horoshop.domain'),
                login: config('services.horoshop.login'),
                password: config('services.horoshop.password'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
