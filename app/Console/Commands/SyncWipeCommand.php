<?php

namespace App\Console\Commands;

use App\Sync\SyncWipe;
use Illuminate\Console\Command;

/**
 * S6-04's manual escape hatch — SyncRunner calls App\Sync\SyncWipe
 * automatically the moment it learns the device is revoked, so a normal
 * revoke-then-reconnect never needs this command run by hand. It exists
 * for support: a corrupted local DB (S13) or a device being decommissioned
 * outside the normal revoke flow.
 */
class SyncWipeCommand extends Command
{
    protected $signature = 'sync:wipe {--force : Skip the confirmation prompt}';

    protected $description = 'Erase all local syncable data and reset sync state.';

    public function handle(SyncWipe $wipe): int
    {
        if (! sync_is_local()) {
            $this->error('sync:wipe is a local-mode command — this install is in cloud mode.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This permanently erases all local customer data and pending changes. Continue?')) {
            return self::FAILURE;
        }

        $wipe->wipe();

        $this->info('Local data wiped.');

        return self::SUCCESS;
    }
}
