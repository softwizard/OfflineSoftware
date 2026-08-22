<?php

namespace App\Sync;

/**
 * Carries which device originated the change currently being applied,
 * server-side, from PushController down into Syncable's model-event
 * hooks — so a device's push and a direct cloud-mode admin edit produce
 * sync_log rows that are identical in shape except for origin_device
 * (set for a push, null for a direct edit).
 */
class SyncContext
{
    protected static ?string $originDevice = null;

    public static function originDevice(): ?string
    {
        return static::$originDevice;
    }

    public static function withOriginDevice(?string $device, callable $callback): mixed
    {
        $previous = static::$originDevice;
        static::$originDevice = $device;

        try {
            return $callback();
        } finally {
            static::$originDevice = $previous;
        }
    }
}
