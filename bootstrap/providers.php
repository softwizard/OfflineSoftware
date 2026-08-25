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
    // manually instead, only when something actually needs it — not on
    // every ordinary artisan/web boot. Two real, found-by-using-it
    // reasons, not one:
    //
    // 1. With NativeServiceProvider active, the very first local-to-cloud
    //    RefreshDatabase transition in a fresh test process leaves
    //    local-only tables behind after migrate:fresh instead of dropping
    //    them (see tests/Feature/Sync/CloudTestCase.php) — excluding it
    //    under APP_ENV=testing (phpunit.xml) fixes that.
    // 2. nativephp/desktop 2.2.1's Native\Desktop\Commands\FreshCommand /
    //    MigrateCommand each declare their name twice (both a
    //    #[AsCommand] attribute and a $name property override) — enough
    //    to make Symfony Console's full command-list resolution throw
    //    "registered under multiple names" the moment anything enumerates
    //    every registered command. `php artisan list` hits this
    //    directly; `php artisan tinker` hits it internally (it builds its
    //    own PsySH command list from every artisan command). Both are
    //    everyday tools that must not require APP_ENV=testing just to
    //    run — so the provider is excluded from ordinary usage entirely,
    //    and loaded only when something native-specific is actually being
    //    invoked (a `native:*` artisan command) or the packaged app's own
    //    runtime is actually running it (NATIVEPHP_RUNNING, set by that
    //    runtime itself, per vendor/nativephp/desktop's own config).
    ...((function () {
        $isTesting = env('APP_ENV') === 'testing';
        // Not just argv[1] — `php artisan help native:install` needs this
        // too, and there argv[1] is "help", not the native: command
        // itself; `php artisan list native` (bare namespace, no colon)
        // needs the exact-match check for the same reason. A plain loop,
        // not array_any() — that's PHP 8.4+ only, and this project (and
        // nativephp/desktop itself) targets php ^8.3.
        $isNativeCommand = false;
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if ($arg === 'native' || str_starts_with((string) $arg, 'native:')) {
                $isNativeCommand = true;
                break;
            }
        }
        $isNativeRuntime = filter_var(env('NATIVEPHP_RUNNING', false), FILTER_VALIDATE_BOOL);

        return ! $isTesting && ($isNativeCommand || $isNativeRuntime)
            ? [NativeServiceProvider::class]
            : [];
    })()),
];
