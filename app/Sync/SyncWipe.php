<?php

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/**
 * S6-04 — "the laptop holds no readable customer data after one cycle."
 * A hard delete, not a tombstone: this device's copy was never its own to
 * keep a record of once it's revoked (the same reasoning as
 * RemoteApplier::applyScopeRevoke, applied to everything at once). What's
 * actually unreadable on disk after this — as opposed to unreachable
 * through the app — is a BitLocker question (build-plan_1.md §6), not this
 * class's.
 */
class SyncWipe
{
    public function wipe(): void
    {
        DB::transaction(function () {
            foreach (config('sync.entities', []) as $entry) {
                DB::table($entry['table'])->delete();
            }

            // No re-push of old changes (S13) — none of them can still be
            // valid once the rows they described are gone.
            DB::table('sync_changes')->delete();

            DB::table('sync_state')->where('id', 1)->update([
                'cursor' => 0,
                'last_success_at' => null,
                'updated_at' => now(),
            ]);
        });

        // A revoked token still authenticates (§S6-04 needs one more
        // request to learn it's revoked at all), but there's no reason to
        // keep hitting the server with it every 5 minutes forever after —
        // blank it so the next sync:run fails fast with a clear "not
        // registered" state instead of a silent, permanent 401 loop.
        $this->forgetDeviceToken();
    }

    protected function forgetDeviceToken(): void
    {
        $path = app()->environmentFilePath();
        $contents = file_exists($path) ? (file_get_contents($path) ?: '') : '';

        $pattern = '/^SYNC_DEVICE_TOKEN=.*/m';

        if (preg_match($pattern, $contents)) {
            file_put_contents($path, preg_replace($pattern, 'SYNC_DEVICE_TOKEN=', $contents));
        }
    }
}
