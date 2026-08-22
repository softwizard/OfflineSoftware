<?php

namespace App\Sync;

/**
 * What PushController needs from any entity registered in config/sync.php,
 * without knowing its concrete class — $modelClass is resolved dynamically
 * via SyncRegistry, so static analysis can't otherwise see past bare
 * Illuminate\Database\Eloquent\Model to a specific entity's $version
 * property or toWirePayload() method.
 */
interface SyncableModel
{
    public function getVersion(): int;

    public function setVersion(int $version): void;

    /**
     * @return array<string, mixed>
     */
    public function toWirePayload(): array;

    /**
     * Push needs to find a record even if it's been soft-deleted (an
     * update/delete pushed for an already-tombstoned row, or a retried
     * create that already landed) — plain find() would miss it. Declared
     * here rather than called as ::withTrashed()->find() directly because
     * bare Illuminate\Database\Eloquent\Model doesn't have withTrashed();
     * only a SoftDeletes-using model does, which static analysis can't see
     * through a dynamically resolved $modelClass.
     */
    public static function findIncludingTrashed(string $id): ?self;

    /**
     * trashed()/restore()/forceDelete() come from the SoftDeletes trait,
     * not bare Model — same reasoning as findIncludingTrashed() above.
     * Every syncable entity is expected to use SoftDeletes (the tombstone
     * -based sync design depends on it structurally, not optionally), so
     * requiring these here is documenting an existing constraint, not
     * adding a new one. No *declared* return types, matching SoftDeletes'
     * own (undeclared) signatures — declaring one here that the trait's
     * actual methods don't have would make every implementer's inherited
     * method incompatible with this interface. The @return docblocks below
     * are for Larastan only; they don't affect that compatibility check.
     */

    /** @return bool */
    public function trashed();

    /** @return bool */
    public function restore();

    /** @return bool|null */
    public function forceDelete();
}
