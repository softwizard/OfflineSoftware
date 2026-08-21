<?php

if (! function_exists('sync_is_local')) {
    function sync_is_local(): bool
    {
        return config('sync.mode') === 'local';
    }
}

if (! function_exists('sync_is_cloud')) {
    function sync_is_cloud(): bool
    {
        return config('sync.mode') === 'cloud';
    }
}
