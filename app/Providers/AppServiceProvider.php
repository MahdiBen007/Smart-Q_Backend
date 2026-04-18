<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\Notification;
use App\Observers\AppointmentObserver;
use App\Observers\NotificationObserver;
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
        Appointment::observe(AppointmentObserver::class);
        Notification::observe(NotificationObserver::class);
    }
}
