<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Local-mode only — mirrors sync/conflicts' cloud-only gate below.
    Route::livewire('customers', 'pages::customers.index')->name('customers.index');
    Route::livewire('sync', 'pages::sync.status')->name('sync.status');

    // Cloud-mode only — the component itself 404s in local mode, since
    // sync_conflicts doesn't exist there (S4-03).
    Route::livewire('sync/conflicts', 'pages::sync.conflicts')->name('sync.conflicts');
    Route::livewire('sync/fleet', 'pages::sync.fleet')->name('sync.fleet');
});

require __DIR__.'/settings.php';
