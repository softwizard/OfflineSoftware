<?php

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/**
 * Keeps the outbox minimal in real time: every pending sync_changes row for
 * one (entity, entity_id) collapses to at most one row after each write.
 * create+delete within the same pending batch cancels to nothing — the
 * record never existed as far as the server needs to know.
 */
class ChangeCoalescer
{
    public static function coalesce(string $entity, string $entityId): void
    {
        $changes = DB::table('sync_changes')
            ->where('entity', $entity)
            ->where('entity_id', $entityId)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($changes->count() <= 1) {
            return;
        }

        $ids = $changes->pluck('id');
        $first = $changes->first();
        $last = $changes->last();

        if ($changes->contains('op', 'create') && $changes->contains('op', 'delete')) {
            DB::table('sync_changes')->whereIn('id', $ids)->delete();

            return;
        }

        if ($changes->contains('op', 'delete')) {
            DB::table('sync_changes')->whereIn('id', $ids)->delete();

            DB::table('sync_changes')->insert([
                'id' => $last->id,
                'entity' => $entity,
                'entity_id' => $entityId,
                'op' => 'delete',
                'payload' => $last->payload,
                'base_version' => $first->base_version,
                'client_updated_at' => $last->client_updated_at,
                'origin_device' => $last->origin_device,
                'status' => 'pending',
                'created_at' => $first->created_at,
                'updated_at' => now(),
            ]);

            return;
        }

        $mergedPayload = [];

        foreach ($changes as $change) {
            $mergedPayload = array_merge($mergedPayload, json_decode($change->payload, true));
        }

        DB::table('sync_changes')->whereIn('id', $ids)->delete();

        DB::table('sync_changes')->insert([
            'id' => $last->id,
            'entity' => $entity,
            'entity_id' => $entityId,
            'op' => $first->op,
            'payload' => json_encode($mergedPayload, JSON_UNESCAPED_UNICODE),
            'base_version' => $first->base_version,
            'client_updated_at' => $last->client_updated_at,
            'origin_device' => $last->origin_device,
            'status' => 'pending',
            'created_at' => $first->created_at,
            'updated_at' => now(),
        ]);
    }
}
