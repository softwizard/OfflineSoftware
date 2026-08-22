<?php

namespace App\Sync;

use DateTimeInterface;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait Syncable
{
    /**
     * Interface-declared ?static; ?self here — Larastan resolves a trait's
     * `static::` to the literal using-class, not true late static binding,
     * so it flags ?static as a lie. self and the using class are the same
     * thing once a trait is flattened in, so this loses nothing real.
     */
    public static function findIncludingTrashed(string $id): ?self
    {
        // Not withTrashed() — it's a macro (SoftDeletingScope::extend()), not
        // a declared Builder method, so PHPStan can't track its return type
        // through the chain back to static. withoutGlobalScope() is a real
        // method with a proper Builder<static> signature.
        return static::withoutGlobalScope(SoftDeletingScope::class)->find($id);
    }

    /**
     * Invariant 5 — one path, branching by mode, not two separate ones.
     * Local mode queues an outbox row (§3.4); cloud mode writes straight
     * to the canonical log (§S2-06). A future direct cloud-mode edit gets
     * correct sync_log rows automatically through this same trait, the
     * same way a push-applied change does — neither can "forget" to call
     * the allocator, because neither calls it directly at all.
     */
    public static function bootSyncable(): void
    {
        static::creating(function ($model) {
            // ConflictResolver (S4-01) needs to know the client_updated_at
            // /origin_device behind a row's *current* value, not just its
            // version — set from the start, the same as on every later
            // update, so the very first edit has something real to compare
            // against instead of nulls.
            if (sync_is_cloud()) {
                $model->last_client_updated_at = SyncContext::clientUpdatedAt();
                $model->last_origin_device = SyncContext::originDevice();
            }
        });

        static::updating(function ($model) {
            // Cloud-side version is a server-owned counter, bumped on every
            // canonical write regardless of what triggered it (push or a
            // direct edit) — it's what base_version comparisons check
            // against, so it must increment before the row that carries it
            // is written, not as an afterthought in whichever controller
            // happens to be calling save(). Local mode never bumps this:
            // a laptop's own edits don't change what "canonical" means.
            if (sync_is_cloud() && $model->isDirty()) {
                $model->version = ($model->getOriginal('version') ?? 0) + 1;
                $model->last_client_updated_at = SyncContext::clientUpdatedAt();
                $model->last_origin_device = SyncContext::originDevice();
            }
        });

        static::created(function ($model) {
            if (sync_is_cloud()) {
                $model->logSyncChange('create', $model->getAttributes());
                $model->checkForDuplicates();

                return;
            }

            $model->recordSyncChange('create', $model->getAttributes());
            $model->coalesceSyncChanges();
        });

        static::updated(function ($model) {
            if (sync_is_cloud()) {
                $model->logSyncChange('update', $model->getChanges());
                $model->checkForDuplicates();

                return;
            }

            $model->recordSyncChange('update', $model->getChanges());
            $model->coalesceSyncChanges();
        });

        static::deleted(function ($model) {
            $deletedAt = ['deleted_at' => $model->{$model->getDeletedAtColumn()}];

            if (sync_is_cloud()) {
                $model->logSyncChange('delete', $deletedAt);

                return;
            }

            $model->recordSyncChange('delete', $deletedAt);
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

    protected function checkForDuplicates(): void
    {
        if (! Sync::isRecording()) {
            return;
        }

        $entity = SyncRegistry::keyForModel($this);

        if (empty(SyncRegistry::softUnique($entity))) {
            return;
        }

        app(DuplicateDetector::class)->check($entity, (string) $this->getKey());
    }

    /**
     * The cloud side's half of Syncable — writes to sync_log instead of
     * sync_changes. A2: a change to the scope column re-emits the full row
     * (fresh seq) for the new owner and a scope_revoke (its own fresh seq)
     * for the old owner, in place of a normal log entry.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function logSyncChange(string $op, array $attrs): void
    {
        if (! Sync::isRecording()) {
            return;                                    // invariant 2
        }

        $entity = SyncRegistry::keyForModel($this);
        $scopeColumn = (new (SyncRegistry::scopeFor($entity)))->column();
        $originDevice = SyncContext::originDevice();

        if ($op === 'update' && array_key_exists($scopeColumn, $attrs)) {
            $oldOwner = $this->getOriginal($scopeColumn);
            $newOwner = $attrs[$scopeColumn];

            if ($oldOwner !== null && $oldOwner !== $newOwner) {
                LogWriter::writeScopeChange(
                    $entity,
                    (string) $this->getKey(),
                    $oldOwner,
                    $newOwner,
                    $this->fullCanonicalPayload($entity),
                    $this->version,
                    $originDevice,
                );

                return;
            }
        }

        $allow = $op === 'delete'
            ? ['deleted_at']
            : [...SyncRegistry::fields($entity), 'deleted_at'];

        if ($op === 'update' && empty(array_intersect(array_keys($attrs), $allow))) {
            return;                                    // nothing syncable actually changed
        }

        $payload = $op === 'delete'
            ? collect($attrs)->only(['deleted_at'])
                ->map(fn ($v) => $v instanceof DateTimeInterface ? SyncTime::wire($v) : $v)
                ->all()
            : $this->fullCanonicalPayload($entity); // A7: always the full canonical row

        LogWriter::writeChange(
            $entity,
            (string) $this->getKey(),
            $op,
            $payload,
            (string) $this->{$scopeColumn},
            $this->version,
            $originDevice,
        );
    }

    /**
     * A7 — the canonical full row, for controllers building a push/pull
     * wire response outside of a model-event context.
     *
     * @return array<string, mixed>
     */
    public function toWirePayload(): array
    {
        return $this->fullCanonicalPayload(SyncRegistry::keyForModel($this));
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    public function getLastClientUpdatedAt(): ?string
    {
        return $this->last_client_updated_at;
    }

    public function getLastOriginDevice(): ?string
    {
        return $this->last_origin_device;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fullCanonicalPayload(string $entity): array
    {
        return collect(SyncRegistry::fields($entity))
            ->mapWithKeys(fn ($field) => [$field => $this->{$field}])
            ->map(fn ($v) => $v instanceof DateTimeInterface ? SyncTime::wire($v) : $v)
            ->all();
    }
}
