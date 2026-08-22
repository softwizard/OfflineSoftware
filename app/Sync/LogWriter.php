<?php

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/**
 * The cloud side's canonical, append-only change log — what pull reads
 * from. Every row's payload is the FULL current row for create/update
 * (A7's "canonical row" guarantee applied uniformly, not just to push
 * responses — see Syncable::fullCanonicalPayload), or a bare
 * {deleted_at} tombstone for delete.
 */
class LogWriter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function writeChange(
        string $entity,
        string $entityId,
        string $op,
        array $payload,
        string $targetOwnerUserId,
        ?int $version,
        ?string $originDevice,
    ): int {
        $seq = SequenceAllocator::next(); // last statement before commit — invariant 3

        DB::table('sync_log')->insert([
            'seq' => $seq,
            'entity' => $entity,
            'entity_id' => $entityId,
            'event' => 'change',
            'op' => $op,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'target_owner_user_id' => $targetOwnerUserId,
            'version' => $version,
            'origin_device' => $originDevice,
            'server_received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $seq;
    }

    /**
     * A2/A3 — a scope-field change re-emits the full row under a fresh seq
     * for the new owner, and emits a scope_revoke under its OWN fresh seq
     * for the old owner. Both seqs are allocated separately (not reused),
     * each the last statement before its own row commits.
     *
     * @param  array<string, mixed>  $fullCanonicalPayload
     * @return array{revoke_seq: int, reemit_seq: int}
     */
    public static function writeScopeChange(
        string $entity,
        string $entityId,
        string $oldOwnerUserId,
        string $newOwnerUserId,
        array $fullCanonicalPayload,
        int $version,
        ?string $originDevice,
    ): array {
        $revokeSeq = SequenceAllocator::next();

        DB::table('sync_log')->insert([
            'seq' => $revokeSeq,
            'entity' => $entity,
            'entity_id' => $entityId,
            'event' => 'scope_revoke',
            'op' => null,
            'payload' => null,
            'target_owner_user_id' => $oldOwnerUserId,
            'version' => null,
            'origin_device' => $originDevice,
            'server_received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reemitSeq = SequenceAllocator::next();

        DB::table('sync_log')->insert([
            'seq' => $reemitSeq,
            'entity' => $entity,
            'entity_id' => $entityId,
            'event' => 'change',
            'op' => 'create',
            'payload' => json_encode($fullCanonicalPayload, JSON_UNESCAPED_UNICODE),
            'target_owner_user_id' => $newOwnerUserId,
            'version' => $version,
            'origin_device' => $originDevice,
            'server_received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['revoke_seq' => $revokeSeq, 'reemit_seq' => $reemitSeq];
    }
}
