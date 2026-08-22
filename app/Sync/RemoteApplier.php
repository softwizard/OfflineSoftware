<?php

namespace App\Sync;

use Illuminate\Database\Eloquent\Model;

/**
 * S3-06 — the one place that applies server-originated data to local
 * storage, used by both PushRunner (its own push's response) and
 * PullRunner (other devices' changes). Always wrapped in
 * Sync::withoutRecording() — invariant 2 — or every applied change would
 * queue right back into the outbox it came from.
 *
 * Restore isn't detected from an explicit `deleted_at: null` marker in the
 * payload — LogWriter's re-emit is always a full canonical snapshot (A7),
 * which never carries deleted_at at all (it's not in the entity's field
 * allowlist). The real signal is structural: an incoming non-delete op for
 * a record this device currently holds as trashed means the canonical
 * record exists and isn't deleted, so it gets restored as part of the
 * upsert.
 */
class RemoteApplier
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function applyChange(string $entity, string $entityId, string $op, ?array $payload, ?int $version): void
    {
        Sync::withoutRecording(function () use ($entity, $entityId, $op, $payload, $version) {
            $modelClass = SyncRegistry::modelFor($entity);

            /** @var (Model&SyncableModel)|null $model */
            $model = $modelClass::findIncludingTrashed($entityId);

            if ($op === 'delete') {
                $model?->delete(); // tombstone — soft delete locally too

                return;
            }

            if (! $model) {
                $model = new $modelClass;
                $model->setAttribute($model->getKeyName(), $entityId);
            } elseif ($model->trashed()) {
                $model->restore();
            }

            $model->fill(collect($payload)->only(SyncRegistry::fields($entity))->all());

            if ($version !== null) {
                $model->setVersion($version);
            }

            $model->save();
        });
    }

    /**
     * A3 — the record left this device's scope; hard-delete, not a
     * tombstone, since it was never this device's to keep a record of.
     */
    public function applyScopeRevoke(string $entity, string $entityId): void
    {
        Sync::withoutRecording(function () use ($entity, $entityId) {
            $modelClass = SyncRegistry::modelFor($entity);

            /** @var (Model&SyncableModel)|null $model */
            $model = $modelClass::findIncludingTrashed($entityId);
            $model?->forceDelete();
        });
    }
}
