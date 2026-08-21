<?php

namespace Tests\Feature\Sync;

use App\Sync\SyncTime;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SyncTimeTest extends TestCase
{
    public function test_wire_format_is_utc_iso8601_milliseconds_literal_z(): void
    {
        $wire = SyncTime::wire(Carbon::create(2026, 8, 21, 9, 14, 3, 'UTC')->addMilliseconds(482));

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $wire);
        $this->assertSame('2026-08-21T09:14:03.482Z', $wire);
    }

    public function test_round_trip_is_lossless_across_three_timezones(): void
    {
        foreach (['America/New_York', 'Europe/London', 'Asia/Tokyo'] as $timezone) {
            $original = Carbon::create(2026, 3, 15, 10, 30, 45, $timezone)->addMilliseconds(250);

            $roundTripped = SyncTime::parse(SyncTime::wire($original));

            $this->assertTrue(
                $original->eq($roundTripped),
                "Round trip through {$timezone} lost precision: {$original->toIso8601String()} != {$roundTripped->toIso8601String()}"
            );
        }
    }

    public function test_parse_returns_utc(): void
    {
        $parsed = SyncTime::parse('2026-08-21T09:14:03.482Z');

        $this->assertSame('UTC', $parsed->timezoneName);
    }
}
