<?php

namespace App\Sync;

use RuntimeException;

class DeviceIdentity
{
    /**
     * The current device's id (e.g. "D07").
     *
     * @throws RuntimeException if SYNC_DEVICE_ID is not configured.
     */
    public static function id(): string
    {
        $id = config('sync.device_id');

        if (! is_string($id) || $id === '') {
            throw new RuntimeException(
                'SYNC_DEVICE_ID is not set. A local install cannot boot without a device identity — set SYNC_DEVICE_ID in .env.'
            );
        }

        return $id;
    }
}
