<?php

namespace App\Console\Commands;

use App\Sync\SyncRunner;
use Illuminate\Console\Command;

/**
 * S3-08 — deliberately an artisan command on the scheduler, not a queued
 * job: a packaged desktop install has no queue worker running, and a
 * single-user laptop doesn't need one. Backoff (S3-09) is what keeps this
 * cheap to fire on a fixed 5-minute schedule even while failing.
 */
class SyncRun extends Command
{
    protected $signature = 'sync:run';

    protected $description = 'Run one sync cycle: probe, handshake, push, pull.';

    public function handle(SyncRunner $runner): int
    {
        if (! sync_is_local()) {
            $this->error('sync:run is a local-mode command — this install is in cloud mode.');

            return self::FAILURE;
        }

        $ran = $runner->run();

        $this->line($ran ? 'Sync run completed.' : 'Sync run skipped (backoff or already running).');

        return self::SUCCESS;
    }
}
