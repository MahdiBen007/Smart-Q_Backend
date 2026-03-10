<?php

use Illuminate\Support\Facades\Route;

Route::prefix('dashboard')
    ->group(base_path('routes/api/dashboard.php'));

Route::prefix('mobile')
    ->group(base_path('routes/api/mobile.php'));
