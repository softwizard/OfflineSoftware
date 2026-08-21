<?php

namespace App\Sync;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait Syncable
{
    public static function bootSyncable(): void
    {
        static::created(function ($model) {
            $model->recordSyncChange('create', $model->getAttributes());
            $model->coalesceSyncChanges();
        });

        static::updated(function ($model) {
            $model->recordSyncChange('update', $model->getChanges());
            $model->coalesceSyncChanges();
        });

        static::deleted(function ($model) {
            $model->recordSyncChange('delete', ['deleted_at' => $model->{$model->getDeletedAtColumn()}]);
            $model->coalesceSyncChanges();
        });
    }

    /**
     * build-plan_1.md §3.4 — the most important function in the system.
     * Implemented exactly as written there, plus the restore fix from the
     * plan's revision history: 'deleted_at' is allowed on 'update' too, not
     * just 'delete', or a restore (deleted_at -> null) silently never
     * reaches the outbox.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function recordSyncChange(string $op, array $attrs): void
    {
        if (sync_is_cloud() || ! Sync::isRecording()) {
            return;                                    // invariant 2
        }

        $entity = SyncRegistry::keyForModel($this);

        $allow = $op === 'delete'
            ? ['deleted_at']
            : [...SyncRegistry::fields($entity), 'deleted_at'];

        $payload = collect($attrs)->only($allow)
            ->map(fn ($v) => $v instanceof DateTimeInterface ? SyncTime::wire($v) : $v)
            ->all();

        if ($op === 'update' && $payload === []) {
            return;                                    // nothing syncable actually changed
        }

        // Participates in the ambient transaction — invariant 1.
        DB::table('sync_changes')->insert([
            'id' => (string) Str::uuid7(),
            'entity' => $entity,
            'entity_id' => $this->getKey(),
            'op' => $op,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'base_version' => $op === 'create' ? null : $this->getOriginal('version'),
            'client_updated_at' => SyncTime::wire(now()),
            'origin_device' => DeviceIdentity::id(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function coalesceSyncChanges(): void
    {
        if (sync_is_cloud() || ! Sync::isRecording()) {
            return;
        }

        ChangeCoalescer::coalesce(SyncRegistry::keyForModel($this), $this->getKey());
    }
}
