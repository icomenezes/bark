<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.casca');
        Paginator::defaultSimpleView('vendor.pagination.casca');

        View::composer('*', function ($view) {
            try {
                $view->with('settings', Setting::current());
            } catch (\Throwable) {
                // Tabela ainda não existe durante migrations iniciais
            }
        });
    }
}
