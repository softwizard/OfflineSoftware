<?php

namespace App\Sync;

use App\Sync\Api\SyncApi;
use Illuminate\Support\Facades\DB;

/**
 * S3-05. Invariant 4: the cursor is committed in the same transaction as
 * the batch it describes. Each page gets its own transaction — if the
 * process dies mid-page-3, pages 1-2's cursor advance already committed
 * (safe to keep), and page 3 wasn't applied at all (also safe: the next
 * run re-requests from the last committed cursor, gap-free).
 */
class PullRunner
{
    public function __construct(
        protected SyncApi $api,
        protected RemoteApplier $applier,
    ) {}

    public function run(int $limit = 500): int
    {
        $cursor = (int) (DB::table('sync_state')->where('id', 1)->value('cursor') ?? 0);
        $totalApplied = 0;

        do {
            $response = $this->api->pull($cursor, $limit);
            $changes = $response['changes'];
            $newCursor = (int) $response['cursor'];

            DB::transaction(function () use ($changes, $newCursor) {
                foreach ($changes as $change) {
                    if ($change['event'] === 'scope_revoke') {
                        $this->applier->applyScopeRevoke($change['entity'], $change['entity_id']);
                    } else {
                        $this->applier->applyChange(
                            $change['entity'],
                            $change['entity_id'],
                            $change['op'],
                            $change['payload'],
                            $change['version'] ?? null,
                        );
                    }
                }

                DB::table('sync_state')->where('id', 1)->update([
                    'cursor' => $newCursor,
                    'updated_at' => now(),
                ]);
            });

            $totalApplied += count($changes);
            $cursor = $newCursor;
        } while (count($changes) >= $limit); // a full page means there may be more

        return $totalApplied;
    }
}
