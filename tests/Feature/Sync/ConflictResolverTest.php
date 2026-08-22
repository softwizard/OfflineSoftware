<?php

namespace Tests\Feature\Sync;

use App\Sync\ConflictResolver;
use App\Sync\SyncTime;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConflictResolverTest extends TestCase
{
    private function resolver(): ConflictResolver
    {
        return new ConflictResolver;
    }

    public function test_a_newer_incoming_change_wins(): void
    {
        $now = Carbon::now();

        $result = $this->resolver()->resolve(
            incomingOp: 'update',
            incomingClientUpdatedAt: SyncTime::wire($now),
            incomingOriginDevice: 'D01',
            currentIsDeleted: false,
            currentClientUpdatedAt: SyncTime::wire($now->clone()->subMinute()),
            currentOriginDevice: 'D02',
            serverReceivedAt: $now,
        );

        $this->assertSame('incoming', $result['winner']);
        $this->assertFalse($result['clock_suspect']);
    }

    public function test_an_older_incoming_change_loses(): void
    {
        $now = Carbon::now();

        $result = $this->resolver()->resolve(
            incomingOp: 'update',
            incomingClientUpdatedAt: SyncTime::wire($now->clone()->subMinute()),
            incomingOriginDevice: 'D01',
            currentIsDeleted: false,
            currentClientUpdatedAt: SyncTime::wire($now),
            currentOriginDevice: 'D02',
            serverReceivedAt: $now,
        );

        $this->assertSame('current', $result['winner']);
    }

    public function test_exact_tie_breaks_on_origin_device_deterministically(): void
    {
        $now = Carbon::now();

        $resultAB = $this->resolver()->resolve('update', SyncTime::wire($now), 'D01', false, SyncTime::wire($now), 'D02', $now);
        $resultBA = $this->resolver()->resolve('update', SyncTime::wire($now), 'D02', false, SyncTime::wire($now), 'D01', $now);

        // D02 > D01 lexicographically — same rule, same answer regardless
        // of which side happens to be "incoming" in this particular call.
        $this->assertSame('current', $resultAB['winner']); // D01 incoming vs D02 current -> D02 wins -> 'current'
        $this->assertSame('incoming', $resultBA['winner']); // D02 incoming vs D01 current -> D02 wins -> 'incoming'
    }

    public function test_delete_beats_update_regardless_of_timestamp(): void
    {
        $now = Carbon::now();

        // Incoming delete is OLDER than current's last update, yet still wins.
        $result = $this->resolver()->resolve(
            incomingOp: 'delete',
            incomingClientUpdatedAt: SyncTime::wire($now->clone()->subMinutes(30)),
            incomingOriginDevice: 'D01',
            currentIsDeleted: false,
            currentClientUpdatedAt: SyncTime::wire($now),
            currentOriginDevice: 'D02',
            serverReceivedAt: $now,
        );

        $this->assertSame('incoming', $result['winner']);
    }

    public function test_a_skewed_delete_still_beats_update_but_is_flagged_anyway(): void
    {
        // Delete-beats-update is a structural rule, not a timestamp claim —
        // a skewed clock doesn't void a deliberate delete. But the skew
        // itself is still worth an admin's attention regardless of who won.
        $now = Carbon::now();

        $result = $this->resolver()->resolve(
            incomingOp: 'delete',
            incomingClientUpdatedAt: SyncTime::wire($now->clone()->subDays(2)),
            incomingOriginDevice: 'D01',
            currentIsDeleted: false,
            currentClientUpdatedAt: SyncTime::wire($now),
            currentOriginDevice: 'D02',
            serverReceivedAt: $now,
        );

        $this->assertSame('incoming', $result['winner']);
        $this->assertTrue($result['clock_suspect']);
    }

    public function test_update_never_beats_an_existing_delete(): void
    {
        $now = Carbon::now();

        $result = $this->resolver()->resolve(
            incomingOp: 'update',
            incomingClientUpdatedAt: SyncTime::wire($now), // newer than current
            incomingOriginDevice: 'D01',
            currentIsDeleted: true,
            currentClientUpdatedAt: SyncTime::wire($now->clone()->subDay()),
            currentOriginDevice: 'D02',
            serverReceivedAt: $now,
        );

        $this->assertSame('current', $result['winner']);
    }

    public function test_a_clock_skewed_incoming_change_loses_and_is_flagged(): void
    {
        $now = Carbon::now();

        $result = $this->resolver()->resolve(
            incomingOp: 'update',
            incomingClientUpdatedAt: SyncTime::wire($now->clone()->subDays(2)), // 2 days back, per S08
            incomingOriginDevice: 'D01',
            currentIsDeleted: false,
            currentClientUpdatedAt: SyncTime::wire($now->clone()->subHours(3)),
            currentOriginDevice: 'D02',
            serverReceivedAt: $now,
        );

        $this->assertSame('current', $result['winner']);
        $this->assertTrue($result['clock_suspect']);
    }

    public function test_skew_within_the_2_hour_threshold_is_not_flagged(): void
    {
        $now = Carbon::now();

        $result = $this->resolver()->resolve(
            incomingOp: 'update',
            incomingClientUpdatedAt: SyncTime::wire($now->clone()->subHour()),
            incomingOriginDevice: 'D01',
            currentIsDeleted: false,
            currentClientUpdatedAt: SyncTime::wire($now->clone()->subDay()),
            currentOriginDevice: 'D02',
            serverReceivedAt: $now,
        );

        $this->assertFalse($result['clock_suspect']);
        $this->assertSame('incoming', $result['winner']);
    }

    public function test_no_prior_tracking_on_the_current_side_lets_incoming_win_by_default(): void
    {
        $now = Carbon::now();

        $result = $this->resolver()->resolve(
            incomingOp: 'update',
            incomingClientUpdatedAt: SyncTime::wire($now),
            incomingOriginDevice: 'D01',
            currentIsDeleted: false,
            currentClientUpdatedAt: null,
            currentOriginDevice: null,
            serverReceivedAt: $now,
        );

        $this->assertSame('incoming', $result['winner']);
    }

    public function test_the_algorithm_is_deterministic_given_the_same_inputs(): void
    {
        // "Both devices independently pick the same winner" (S4-01) — a
        // pure function of its inputs, called twice, agrees with itself.
        $now = Carbon::now();
        $args = ['update', SyncTime::wire($now), 'D01', false, SyncTime::wire($now->clone()->subMinute()), 'D02', $now];

        $first = $this->resolver()->resolve(...$args);
        $second = $this->resolver()->resolve(...$args);

        $this->assertSame($first, $second);
    }
}
