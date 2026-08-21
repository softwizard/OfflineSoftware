<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncStatus extends Command
{
    protected $signature = 'sync:status';

    protected $description = 'Show the local outbox status: pending changes, oldest age, cursor, failures.';

    public function handle(): int
    {
        $pending = DB::table('sync_changes')->where('status', 'pending')->count();
        $oldest = DB::table('sync_changes')->where('status', 'pending')->min('created_at');
        $failed = DB::table('sync_changes')->where('status', 'failed')->count();

        $this->line("Pending changes: {$pending}");
        $this->line('Oldest pending: '.($oldest ? Carbon::parse($oldest)->diffForHumans() : '—'));
        $this->line("Failed changes: {$failed}");
        // sync_state (the pull cursor) doesn't exist until Sprint 3 (S3-01).
        $this->line('Pull cursor: not yet tracked (lands in Sprint 3)');

        return self::SUCCESS;
    }
}
