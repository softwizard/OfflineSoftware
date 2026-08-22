<?php

namespace App\Sync;

use App\Sync\Api\SyncApi;
use Illuminate\Support\Facades\DB;

/**
 * S3-04. Nothing is written to sync_changes until after a successful
 * response — if $api->push() throws (transport failure), every row it
 * read is still untouched and still 'pending'; the next run reads the
 * exact same batch with the exact same change ids, and A4 (server-side
 * receipts) makes that retry produce the original outcome, not a
 * duplicate or a phantom conflict.
 */
class PushRunner
{
    public function __construct(
        protected SyncApi $api,
        protected RemoteApplier $applier,
    ) {}

    public function run(int $batchSize = 500): int
    {
        $pending = DB::table('sync_changes')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $wire = $pending->map(fn ($row) => [
            'id' => $row->id,
            'entity' => $row->entity,
            'entity_id' => $row->entity_id,
            'op' => $row->op,
            'base_version' => $row->base_version,
            'client_updated_at' => $row->client_updated_at,
            'origin_device' => $row->origin_device,
            'payload' => json_decode($row->payload, true),
        ])->values();

        $changesById = $wire->keyBy('id');

        $response = $this->api->push($wire->all()); // throws on transport failure — nothing above has mutated anything yet

        foreach ($response['results'] as $result) {
            $original = $changesById->get($result['id']);

            if ($original) {
                $this->applyResult($result, $original);
            }
        }

        return count($response['results']);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $original
     */
    protected function applyResult(array $result, array $original): void
    {
        DB::transaction(function () use ($result, $original) {
            if (in_array($result['outcome'], ['applied', 'conflict'], true) && isset($result['row'])) {
                $this->applier->applyChange(
                    $original['entity'],
                    $original['entity_id'],
                    $original['op'],
                    $result['row'],
                    $result['version'] ?? null,
                );
            }

            if ($result['outcome'] === 'applied') {
                // Done — nothing left for the outbox to say about it.
                DB::table('sync_changes')->where('id', $result['id'])->delete();

                return;
            }

            // 'conflict' / 'rejected' — kept, not deleted: S4-04's local
            // notice and S5-04's /sync page both need to show the user
            // which of their edits didn't make it and why.
            DB::table('sync_changes')->where('id', $result['id'])->update([
                'status' => $result['outcome'],
                'error' => isset($result['errors']) ? json_encode($result['errors'], JSON_UNESCAPED_UNICODE) : null,
                'updated_at' => now(),
            ]);
        });
    }
}
