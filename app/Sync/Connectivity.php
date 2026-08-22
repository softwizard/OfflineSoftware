<?php

namespace App\Sync;

use App\Sync\Api\SyncApi;
use Illuminate\Support\Facades\Cache;

/**
 * "Never trusts the OS interface state" — a laptop can report a WiFi
 * adapter as up while the actual server is unreachable (captive portal,
 * VPN required, DNS broken). The only real signal is a live ping.
 */
class Connectivity
{
    protected const CACHE_KEY = 'sync:connectivity:online';

    protected const CACHE_SECONDS = 30;

    public function __construct(protected SyncApi $api) {}

    public function online(): bool
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->api->ping());
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
