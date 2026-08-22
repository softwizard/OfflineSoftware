<?php

namespace App\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S4-05 / A6. Runs after every cloud-side create/update — no unique index
 * exists to catch this at the DB layer (that's the whole point of A6:
 * two offline devices can't violate a constraint against each other), so
 * this is the only place a collision on a soft-unique field ever gets
 * noticed. Both records are kept; both get flagged for review.
 */
class DuplicateDetector
{
    public function check(string $entity, string $entityId): void
    {
        $fields = SyncRegistry::softUnique($entity);

        if (empty($fields)) {
            return;
        }

        $modelClass = SyncRegistry::modelFor($entity);
        $row = $modelClass::find($entityId);

        if (! $row) {
            return;
        }

        $table = SyncRegistry::table($entity);

        foreach ($fields as $field) {
            $value = $row->{$field};

            if ($value === null || $value === '') {
                continue;
            }

            $matchIds = DB::table($table)
                ->where($field, $value)
                ->whereNull('deleted_at')
                ->pluck('id');

            if ($matchIds->count() < 2) {
                continue; // no collision
            }

            foreach ($matchIds as $matchId) {
                $this->flag($entity, $matchId, $field, $value);
            }
        }
    }

    protected function flag(string $entity, string $entityId, string $field, string $value): void
    {
        $exists = DB::table('duplicate_candidates')
            ->where('entity', $entity)
            ->where('entity_id', $entityId)
            ->where('field', $field)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('duplicate_candidates')->insert([
            'id' => (string) Str::uuid7(),
            'entity' => $entity,
            'entity_id' => $entityId,
            'field' => $field,
            'value' => $value,
            'status' => 'unresolved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
