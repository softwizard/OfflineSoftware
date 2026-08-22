<?php

namespace App\Sync;

use App\Sync\Api\SyncApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * S3-07 — probe, handshake, push, pull, log. Cache::lock ensures that of
 * two concurrent runs (a scheduled tick overlapping a manual "Sync Now"),
 * exactly one executes; the other's get() returns false immediately
 * rather than queuing or blocking.
 */
class SyncRunner
{
    public function __construct(
        protected Connectivity $connectivity,
        protected SyncApi $api,
        protected PushRunner $pushRunner,
        protected PullRunner $pullRunner,
        protected Backoff $backoff,
    ) {}

    /**
     * @return bool true if this call actually ran (regardless of outcome),
     *              false if skipped — backoff still cooling down, or another
     *              run already held the lock.
     */
    public function run(): bool
    {
        if ($this->backoff->shouldSkip()) {
            return false;
        }

        return (bool) Cache::lock('sync:run', 300)->get(fn () => $this->attempt());
    }

    protected function attempt(): true
    {
        $runId = DB::table('sync_runs')->insertGetId([
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stage = 'probe';

        try {
            if (! $this->connectivity->online()) {
                $this->finish($runId, 'offline', $stage, 'server unreachable');

                return true;
            }

            $stage = 'handshake';
            $this->api->handshake();

            $stage = 'push';
            $pushed = $this->pushRunner->run();

            $stage = 'pull';
            $pulled = $this->pullRunner->run();

            $this->finish($runId, 'success', null, null, $pushed, $pulled);

            // S5-03's status bar reads this — set here, not anywhere in
            // PullRunner, since "last success" means a full cycle
            // completed, not just that one stage happened to finish.
            DB::table('sync_state')->where('id', 1)->update(['last_success_at' => now()]);
        } catch (Throwable $e) {
            $this->finish($runId, 'failed', $stage, $e->getMessage());
        }

        return true;
    }

    protected function finish(int $runId, string $status, ?string $stage, ?string $error, int $pushed = 0, int $pulled = 0): void
    {
        DB::table('sync_runs')->where('id', $runId)->update([
            'status' => $status,
            'stage' => $stage,
            'error' => $error,
            'pushed_count' => $pushed,
            'pulled_count' => $pulled,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
