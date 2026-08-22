<?php

namespace App\Console\Commands;

use App\Sync\Api\SyncApi;
use App\Sync\RemoteApplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * S6-01 — "a blank laptop is provisioned from a snapshot in under five
 * minutes." Also S13's recovery path: a corrupted local DB re-seeds from
 * here and ends up matching the server exactly, with no duplicates and no
 * re-push of whatever the corruption left in the outbox. Both cases need
 * the same thing — wipe first, then load — so this is deliberately the
 * same shape as App\Sync\SyncWipe plus a fill, not a separate merge
 * strategy that would have to reason about what's already there.
 */
class SyncSeed extends Command
{
    protected $signature = 'sync:seed {--force : Skip the confirmation prompt}';

    protected $description = 'Replace all local syncable data with a fresh snapshot from the server.';

    public function handle(SyncApi $api, RemoteApplier $applier): int
    {
        if (! sync_is_local()) {
            $this->error('sync:seed is a local-mode command — this install is in cloud mode.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This replaces all local customer data and clears any unsynced pending changes with a fresh copy from the server. Continue?')) {
            return self::FAILURE;
        }

        $this->line('Fetching snapshot…');
        $snapshot = $api->snapshot();

        $count = 0;

        DB::transaction(function () use ($snapshot, $applier, &$count) {
            foreach (config('sync.entities', []) as $key => $entry) {
                DB::table($entry['table'])->delete();

                /** @var array<int, array<string, mixed>> $rows */
                $rows = $snapshot['entities'][$key] ?? [];

                foreach ($rows as $row) {
                    $fields = collect($row)->except(['id', 'version'])->all();

                    // create — the table was just emptied, so this can
                    // never collide with an existing row. Goes through the
                    // same applier the pull path uses (RemoteApplier wraps
                    // it in Sync::withoutRecording — invariant 2 — so
                    // seeding never queues its own outbox rows), rather
                    // than a bespoke insert here.
                    $applier->applyChange($key, $row['id'], 'create', $fields, $row['version']);
                    $count++;
                }
            }

            DB::table('sync_changes')->delete();

            DB::table('sync_state')->where('id', 1)->update([
                'cursor' => $snapshot['cursor'],
                'last_success_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->info("Seeded {$count} record(s). Local cursor set to {$snapshot['cursor']}.");

        return self::SUCCESS;
    }
}
