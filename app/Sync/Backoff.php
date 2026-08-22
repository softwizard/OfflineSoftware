<?php

namespace App\Sync;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * S3-09 — 1m, 5m, 15m, then capped at 1h. The scheduler still fires
 * sync:run every 5 minutes regardless (S3-08); this is what makes a
 * failing run skip most of those invocations instead of hammering a
 * server that's already said no, and resume on its own once it's back.
 */
class Backoff
{
    protected const TIERS = [60, 300, 900, 3600];

    public function shouldSkip(): bool
    {
        $failures = $this->consecutiveFailures();

        if ($failures === 0) {
            return false;
        }

        $delay = self::TIERS[$failures - 1] ?? self::TIERS[array_key_last(self::TIERS)];
        $lastAttemptAt = DB::table('sync_runs')->orderByDesc('id')->value('started_at');

        return $lastAttemptAt && Carbon::parse($lastAttemptAt)->addSeconds($delay)->isFuture();
    }

    protected function consecutiveFailures(): int
    {
        $count = 0;

        foreach (DB::table('sync_runs')->orderByDesc('id')->limit(20)->pluck('status') as $status) {
            if (! in_array($status, ['failed', 'offline'], true)) {
                break;
            }

            $count++;
        }

        return $count;
    }
}
