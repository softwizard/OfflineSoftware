<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Sync\SyncableModel;
use App\Sync\SyncRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * S6-01 — a full-state read for provisioning (`sync:seed`), as opposed to
 * pull's incremental log replay. "Scoped" — every registered entity,
 * filtered to the device's own owner scope, same as pull. "Cached" — a
 * fresh laptop image or a corrupted-DB reseed (S13) can legitimately hit
 * this more than once in a short window; the 30s window matches
 * Connectivity's own (App\Sync\Connectivity) rather than inventing a new
 * rhythm.
 */
class SnapshotController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        if (! $device->owner_user_id) {
            return response()->json(['entities' => [], 'cursor' => 0]);
        }

        $snapshot = Cache::remember(
            "sync:snapshot:{$device->owner_user_id}",
            30,
            fn () => $this->build($device->owner_user_id),
        );

        return response()->json($snapshot);
    }

    /**
     * @return array{entities: array<string, array<int, array<string, mixed>>>, cursor: int}
     */
    protected function build(string $ownerUserId): array
    {
        return DB::transaction(function () use ($ownerUserId) {
            // Locking the same row SequenceAllocator::next() locks (invariant
            // 3) means no write anywhere in the system can allocate a new seq
            // — and therefore commit its row change — until this transaction
            // releases it. The cursor read below is a true boundary: every
            // row this snapshot reads is at or before it, and nothing after
            // it can have landed in between.
            $cursor = (int) DB::table('sync_sequence')->where('id', 1)->lockForUpdate()->value('value');

            $entities = [];

            foreach (config('sync.entities', []) as $key => $entry) {
                $modelClass = SyncRegistry::modelFor($key);
                $scopeColumn = (new (SyncRegistry::scopeFor($key)))->column();

                $rows = [];

                foreach ($modelClass::where($scopeColumn, $ownerUserId)->get() as $row) {
                    /** @var Model&SyncableModel $row */
                    $rows[] = [
                        'id' => $row->getKey(),
                        'version' => $row->getVersion(),
                        ...$row->toWirePayload(),
                    ];
                }

                $entities[$key] = $rows;
            }

            return ['entities' => $entities, 'cursor' => $cursor];
        });
    }
}
