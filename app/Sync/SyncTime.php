<?php

namespace App\Sync;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The wire format's one timestamp rule (build-plan_1.md §3.2): UTC,
 * ISO-8601, milliseconds, literal Z. Never local time, never a bare offset.
 */
class SyncTime
{
    public static function wire(DateTimeInterface $time): string
    {
        return CarbonImmutable::instance($time)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function parse(string $wire): CarbonImmutable
    {
        return CarbonImmutable::parse($wire)->utc();
    }
}
