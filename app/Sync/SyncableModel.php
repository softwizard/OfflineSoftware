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
}
