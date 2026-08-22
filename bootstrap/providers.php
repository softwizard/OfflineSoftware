<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\SyncServiceProvider;
use Native\Desktop\NativeServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    SyncServiceProvider::class,

    // S7-01 — composer.json opts nativephp/desktop out of Laravel's normal
    // package auto-discovery ("dont-discover"), so it's registered here
    // manually instead, and only outside the test environment. Found via
    // real reproduction, not suspicion: with NativeServiceProvider active,
    // the very first local-mode-to-cloud-mode RefreshDatabase transition
    // in a fresh process (e.g. a plain local test immediately followed by
    // a CloudTestCase one — see tests/Feature/Sync/CloudTestCase.php)
    // leaves the local-only tables (sync_changes, code_sequences, ...)
    // behind after `migrate:fresh`, instead of dropping them — sequences
    // of only same-mode tests never triggered it, which is why it slipped
    // past 6 sprints of testing until Sprint 7 changed which tests ran
    // adjacent to which. Excluding the provider during `php artisan test`
    // (APP_ENV=testing, set in phpunit.xml) makes the whole suite pass
    // again; native:install/native:run/native:dev — real desktop
    // packaging and its own artisan commands — need the provider and
    // still get it, since none of those run under APP_ENV=testing.
    ...(env('APP_ENV') === 'testing' ? [] : [NativeServiceProvider::class]),
];
