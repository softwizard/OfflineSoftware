<?php

namespace App\Sync;

/**
 * Carries per-change metadata from PushController down into Syncable's
 * model-event hooks — so a device's push and a direct cloud-mode admin
 * edit produce sync_log rows (and, since Sprint 4, last_client_updated_at
 * /last_origin_device tracking for ConflictResolver) identical in shape
 * except for these two fields, which are set for a push and null for a
 * direct edit.
 */
class SyncContext
{
    protected static ?string $originDevice = null;

    protected static ?string $clientUpdatedAt = null;

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

    public static function clientUpdatedAt(): ?string
    {
        return static::$clientUpdatedAt;
    }

    public static function withClientUpdatedAt(?string $wireTimestamp, callable $callback): mixed
    {
        $previous = static::$clientUpdatedAt;
        static::$clientUpdatedAt = $wireTimestamp;

        try {
            return $callback();
        } finally {
            static::$clientUpdatedAt = $previous;
        }
    }
}
