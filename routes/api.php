<?php

use App\Http\Controllers\Sync\DeviceRegistrationController;
use App\Http\Controllers\Sync\HandshakeController;
use App\Http\Controllers\Sync\PingController;
use App\Http\Controllers\Sync\PullController;
use App\Http\Controllers\Sync\PushController;
use Illuminate\Support\Facades\Route;

Route::prefix('sync')->group(function () {
    Route::post('register', [DeviceRegistrationController::class, 'store']);
    // Route::get() also registers HEAD automatically (Router::get() ->
    // addRoute(['GET', 'HEAD'], ...)) — no separate Route::head() exists.
    Route::get('ping', [PingController::class, 'show']); // no auth, no DB — see PingController

    Route::middleware(['device', 'schema.version'])->group(function () {
        Route::post('handshake', [HandshakeController::class, 'store']);
        Route::post('push', [PushController::class, 'store']);
        Route::get('pull', [PullController::class, 'index']);
    });
});
