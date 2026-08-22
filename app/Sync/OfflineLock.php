<?php

namespace App\Sync;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * S6-03 — "day 15 without sync blocks writes." Measured from
 * sync_state.last_success_at, the same column S5-03's status bar already
 * shows as "Last synced" — a device that has never synced at all (fresh
 * install, before its first sync:seed/sync:run) isn't locked: the count
 * -down protects against staying silent too long *after* being live, it
 * isn't a bar to ever going live.
 */
class OfflineLock
{
    public const MAX_OFFLINE_DAYS = 14;

    public const WARNING_AT_DAYS = 10;

    /**
     * @return array{locked: bool, warning: bool, days_offline: ?int, days_remaining: ?int}
     */
    public function status(): array
    {
        $lastSuccessAt = DB::table('sync_state')->where('id', 1)->value('last_success_at');

        if (! $lastSuccessAt) {
            return ['locked' => false, 'warning' => false, 'days_offline' => null, 'days_remaining' => null];
        }

        $days = (int) Carbon::parse($lastSuccessAt)->diffInDays(now());

        return [
            'locked' => $days >= self::MAX_OFFLINE_DAYS,
            'warning' => $days >= self::WARNING_AT_DAYS,
            'days_offline' => $days,
            'days_remaining' => max(0, self::MAX_OFFLINE_DAYS - $days),
        ];
    }

    public function assertWritable(): void
    {
        $status = $this->status();

        if ($status['locked']) {
            throw new OfflineLockException(
                "This device hasn't reached the server in {$status['days_offline']} days (limit ".self::MAX_OFFLINE_DAYS.'). '.
                'Writes are locked until it syncs — connect to the network and press Sync Now.'
            );
        }
    }
}
