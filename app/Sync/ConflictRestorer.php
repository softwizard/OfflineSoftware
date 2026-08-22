<?php

namespace App\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * S4-03's "restore losing value" — an admin override of an already
 * -automatic ConflictResolver decision. Goes through the same
 * Syncable/LogWriter path as any other cloud-side write (invariant 5),
 * so the restore gets a fresh seq and genuinely "propagates to all
 * devices on next pull" rather than being a local-only correction.
 */
class ConflictRestorer
{
    /**
     * @param  object{id: string, entity: string, entity_id: string, winner: string, incoming_op: string, current_was_deleted: bool|int, incoming_payload: string, current_payload: string}  $conflict
     *                                                                                                                                                                                                  a sync_conflicts row, as returned by DB::table('sync_conflicts')->first()
     */
    public function restore(object $conflict): void
    {
        $modelClass = SyncRegistry::modelFor($conflict->entity);
        $fields = SyncRegistry::fields($conflict->entity);

        // The op that made the LOSING side what it was — the flip side of
        // whichever one actually won.
        $losingWasDelete = $conflict->winner === 'incoming'
            ? (bool) $conflict->current_was_deleted
            : $conflict->incoming_op === 'delete';

        /** @var array<string, mixed> $losingPayload */
        $losingPayload = json_decode(
            $conflict->winner === 'incoming' ? $conflict->current_payload : $conflict->incoming_payload,
            true,
        );

        DB::transaction(function () use ($conflict, $modelClass, $fields, $losingWasDelete, $losingPayload) {
            SyncContext::withOriginDevice(null, fn () => SyncContext::withClientUpdatedAt(
                SyncTime::wire(now()),
                function () use ($conflict, $modelClass, $fields, $losingWasDelete, $losingPayload) {
                    /** @var (Model&SyncableModel)|null $row */
                    $row = $modelClass::findIncludingTrashed($conflict->entity_id);

                    if ($losingWasDelete) {
                        $row?->delete();

                        return;
                    }

                    if (! $row) {
                        /** @var Model&SyncableModel $row */
                        $row = new $modelClass;
                        $row->setAttribute($row->getKeyName(), $conflict->entity_id);
                    } elseif ($row->trashed()) {
                        $row->restore();
                    }

                    $row->fill(collect($losingPayload)->only($fields)->all());
                    $row->save();
                },
            ));

            DB::table('sync_conflicts')->where('id', $conflict->id)->update([
                'status' => 'restored',
                'updated_at' => now(),
            ]);
        });
    }
}
