<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\Notification;
use App\Observers\AppointmentObserver;
use App\Observers\NotificationObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('mobile-auth', function (Request $request): Limit {
            $identifier = (string) $request->input('identifier', '');
            $identityScope = trim(mb_strtolower($identifier)) !== ''
                ? trim(mb_strtolower($identifier))
                : ($request->ip() ?? 'unknown-ip');

            return Limit::perMinute(12)->by('mobile-auth:'.$identityScope);
        });

        Appointment::observe(AppointmentObserver::class);
        Notification::observe(NotificationObserver::class);
    }
}
