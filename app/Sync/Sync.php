<?php

namespace App\Sync;

/**
 * Invariant 2: remote applies never record to the outbox. Every apply of
 * server data must go through withoutRecording(), or the device pushes
 * back what it just received, forever.
 */
class Sync
{
    protected static bool $recording = true;

    public static function isRecording(): bool
    {
        return static::$recording;
    }

    public static function withoutRecording(callable $callback): mixed
    {
        $previous = static::$recording;
        static::$recording = false;

        try {
            return $callback();
        } finally {
            static::$recording = $previous;
        }
    }
}
