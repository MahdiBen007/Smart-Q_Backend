<?php

use App\Http\Controllers\Api\Dashboard\Analytics\AnalyticsController;
use App\Http\Controllers\Api\Dashboard\Appointments\AppointmentController;
use App\Http\Controllers\Api\Dashboard\Auth\AuthController;
use App\Http\Controllers\Api\Dashboard\Branches\BranchController;
use App\Http\Controllers\Api\Dashboard\Dashboard\DashboardController;
use App\Http\Controllers\Api\Dashboard\Layout\LayoutController;
use App\Http\Controllers\Api\Dashboard\QueueMonitor\QueueMonitorController;
use App\Http\Controllers\Api\Dashboard\Services\ServiceController;
use App\Http\Controllers\Api\Dashboard\Settings\SettingsController;
use App\Http\Controllers\Api\Dashboard\Staff\StaffController;
use App\Http\Controllers\Api\Dashboard\WalkIns\WalkInController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('jwt.auth')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });

    Route::prefix('layout')->group(function () {
        Route::get('top-navbar', [LayoutController::class, 'topNavbar']);
    });

    Route::prefix('notifications')->group(function () {
        Route::patch('read-all', [LayoutController::class, 'markAllRead']);
        Route::patch('{notification}/read', [LayoutController::class, 'markNotificationRead']);
    });

    Route::controller(DashboardController::class)->group(function () {
        Route::get('kpis', 'kpis');
        Route::get('traffic', 'traffic');
        Route::get('live-queue', 'liveQueue');
        Route::get('queue-performance', 'queuePerformance');
    });

    Route::prefix('queue-monitor')->group(function () {
        Route::get('bootstrap', [QueueMonitorController::class, 'bootstrap']);
        Route::post('entries', [QueueMonitorController::class, 'store']);
        Route::patch('entries/{entry}/call', [QueueMonitorController::class, 'call']);
        Route::patch('entries/{entry}/skip', [QueueMonitorController::class, 'skip']);
        Route::patch('entries/{entry}/complete', [QueueMonitorController::class, 'complete']);
        Route::patch('session/status', [QueueMonitorController::class, 'updateSessionStatus']);
        Route::post('reset', [QueueMonitorController::class, 'reset']);
        Route::post('clear-waiting', [QueueMonitorController::class, 'clearWaiting']);
    });

    Route::prefix('appointments')->group(function () {
        Route::get('bootstrap', [AppointmentController::class, 'bootstrap']);
        Route::get('/', [AppointmentController::class, 'index']);
        Route::get('{appointment}', [AppointmentController::class, 'show']);
        Route::post('{appointment}/open-ticket', [AppointmentController::class, 'openTicket']);
        Route::patch('{appointment}/mark-no-show', [AppointmentController::class, 'markNoShow']);
        Route::patch('{appointment}/cancel', [AppointmentController::class, 'cancel']);
    });

    Route::prefix('walk-ins')->group(function () {
        Route::get('bootstrap', [WalkInController::class, 'bootstrap']);
        Route::post('/', [WalkInController::class, 'store']);
        Route::post('{ticket}/check-in', [WalkInController::class, 'checkIn']);
        Route::patch('{ticket}/escalate', [WalkInController::class, 'escalate']);
        Route::patch('{ticket}/complete', [WalkInController::class, 'complete']);
    });

    Route::prefix('branches')->group(function () {
        Route::get('bootstrap', [BranchController::class, 'bootstrap']);
        Route::get('/', [BranchController::class, 'index']);
        Route::get('{branch}', [BranchController::class, 'show']);
        Route::post('/', [BranchController::class, 'store']);
        Route::patch('{branch}', [BranchController::class, 'update']);
        Route::patch('{branch}/status', [BranchController::class, 'updateStatus']);
        Route::get('{branch}/services', [BranchController::class, 'services']);
    });

    Route::prefix('services')->group(function () {
        Route::get('bootstrap', [ServiceController::class, 'bootstrap']);
        Route::get('/', [ServiceController::class, 'index']);
        Route::get('{service}', [ServiceController::class, 'show']);
        Route::post('/', [ServiceController::class, 'store']);
        Route::patch('{service}', [ServiceController::class, 'update']);
        Route::patch('{service}/status', [ServiceController::class, 'updateStatus']);
    });

    Route::prefix('staff')->group(function () {
        Route::get('bootstrap', [StaffController::class, 'bootstrap']);
        Route::get('/', [StaffController::class, 'index']);
        Route::get('{staff}', [StaffController::class, 'show']);
        Route::post('/', [StaffController::class, 'store']);
        Route::patch('{staff}', [StaffController::class, 'update']);
        Route::patch('{staff}/branch', [StaffController::class, 'updateBranch']);
        Route::patch('{staff}/status', [StaffController::class, 'updateStatus']);
        Route::post('{staff}/avatar', [StaffController::class, 'uploadAvatar']);
    });

    Route::prefix('analytics')->group(function () {
        Route::get('bootstrap', [AnalyticsController::class, 'bootstrap']);
    });

    Route::prefix('settings')->group(function () {
        Route::get('dashboard', [SettingsController::class, 'show']);
        Route::put('dashboard', [SettingsController::class, 'update']);
    });
});
