<?php

namespace App\Sync;

use DateTimeInterface;

/**
 * S4-01. A pure function of its inputs — same incoming/current pair always
 * resolves to the same winner, which is what "both devices independently
 * pick the same winner" actually depends on: only the server ever calls
 * this today, but the algorithm itself has no hidden state to disagree
 * with itself over.
 *
 * Order of rules, each short-circuiting the next:
 *   1. Delete-beats-update (S05) — a delete always wins over a live edit,
 *      regardless of timestamps. This runs BEFORE the skew guard
 *      deliberately: a device reporting "the user deleted this" is a
 *      discrete, authenticated action, not a timestamp claim — a skewed
 *      clock shouldn't void it. Skew is still flagged either way, since
 *      that's about the device's clock health, not this one decision.
 *   2. A8 clock-skew guard — from here on the comparison genuinely
 *      depends on trusting the incoming timestamp, so an incoming change
 *      whose client_updated_at is more than 2h off the server's
 *      received-at can't compete on timestamp at all; it loses outright.
 *   3. A5 LWW on client_updated_at.
 *   4. Exact tie: origin_device string comparison — arbitrary but
 *      deterministic (the larger device code wins).
 */
class ConflictResolver
{
    public const SKEW_THRESHOLD_HOURS = 2;

    /**
     * @return array{winner: 'incoming'|'current', clock_suspect: bool}
     */
    public function resolve(
        string $incomingOp,
        string $incomingClientUpdatedAt,
        string $incomingOriginDevice,
        bool $currentIsDeleted,
        ?string $currentClientUpdatedAt,
        ?string $currentOriginDevice,
        DateTimeInterface $serverReceivedAt,
    ): array {
        $clockSuspect = $this->isClockSkewed($incomingClientUpdatedAt, $serverReceivedAt);
        $incomingIsDelete = $incomingOp === 'delete';

        if ($incomingIsDelete && ! $currentIsDeleted) {
            return ['winner' => 'incoming', 'clock_suspect' => $clockSuspect];
        }

        if ($currentIsDeleted && ! $incomingIsDelete) {
            return ['winner' => 'current', 'clock_suspect' => $clockSuspect];
        }

        if ($clockSuspect) {
            return ['winner' => 'current', 'clock_suspect' => true];
        }

        if ($currentClientUpdatedAt === null) {
            // Nothing to compare against (e.g. a row predating this
            // tracking) — no basis to prefer the existing value.
            return ['winner' => 'incoming', 'clock_suspect' => false];
        }

        $incoming = SyncTime::parse($incomingClientUpdatedAt);
        $current = SyncTime::parse($currentClientUpdatedAt);

        if ($incoming->gt($current)) {
            return ['winner' => 'incoming', 'clock_suspect' => false];
        }

        if ($current->gt($incoming)) {
            return ['winner' => 'current', 'clock_suspect' => false];
        }

        $winner = $incomingOriginDevice > ($currentOriginDevice ?? '') ? 'incoming' : 'current';

        return ['winner' => $winner, 'clock_suspect' => false];
    }

    protected function isClockSkewed(string $clientUpdatedAt, DateTimeInterface $serverReceivedAt): bool
    {
        $clientTime = SyncTime::parse($clientUpdatedAt);
        $diffHours = abs($clientTime->diffInMinutes($serverReceivedAt)) / 60;

        return $diffHours > self::SKEW_THRESHOLD_HOURS;
    }
}
