<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Cloud-mode only — the component itself 404s in local mode, since
    // sync_conflicts doesn't exist there (S4-03).
    Route::livewire('sync/conflicts', 'pages::sync.conflicts')->name('sync.conflicts');
});

require __DIR__.'/settings.php';
