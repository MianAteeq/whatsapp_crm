<?php

namespace App\Providers;

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
        \App\Models\Contact::observe(\App\Observers\ContactObserver::class);
        \App\Models\WhatsappTemplate::observe(\App\Observers\WhatsappTemplateObserver::class);
    }
}
