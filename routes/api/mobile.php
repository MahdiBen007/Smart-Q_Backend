<?php

use App\Http\Controllers\Api\Mobile\Auth\AuthController;
use App\Http\Controllers\Api\Mobile\Bookings\BookingController;
use App\Http\Controllers\Api\Mobile\Branches\BranchController;
use App\Http\Controllers\Api\Mobile\Dashboard\DashboardController;
use App\Http\Controllers\Api\Mobile\Preferences\PreferenceController;
use App\Http\Controllers\Api\Mobile\Queue\QueueController;
use App\Http\Controllers\Api\Mobile\Notifications\DeviceTokenController;
use App\Http\Controllers\Api\Mobile\Notifications\NotificationController;
use App\Http\Controllers\Api\Mobile\Profile\ProfileController;
use App\Http\Controllers\Api\Mobile\Services\ServiceController;
use App\Http\Controllers\Api\Mobile\Tickets\TicketController;
use App\Http\Controllers\Api\Mobile\TimeSlots\TimeSlotController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('jwt.auth')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });

    Route::get('dashboard', [DashboardController::class, 'show']);
    Route::get('profile', [ProfileController::class, 'show']);
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);

    Route::get('preferences', [PreferenceController::class, 'show']);
    Route::patch('preferences', [PreferenceController::class, 'update']);

    Route::get('branches', [BranchController::class, 'index']);
    Route::get('services', [ServiceController::class, 'index']);
    Route::get('time-slots', [TimeSlotController::class, 'index']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/device-token', [DeviceTokenController::class, 'store']);
    Route::delete('notifications/device-token', [DeviceTokenController::class, 'destroy']);
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);
    Route::post('notifications/delete-many', [NotificationController::class, 'destroyMany']);

    Route::get('tickets', [TicketController::class, 'index']);
    Route::get('tickets/{appointment}', [TicketController::class, 'show']);
    Route::get('queue/status', [QueueController::class, 'status']);

    Route::post('confirm-booking', [BookingController::class, 'confirm']);
    Route::post('cancel-booking', [BookingController::class, 'cancel']);
});
