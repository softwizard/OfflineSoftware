<?php

namespace App\Sync\Scopes;

/**
 * Single-owner scope for v1 — every syncable entity has exactly one
 * owner_user_id in scope at a time (build-plan_1.md §3.3). The query-side
 * filtering (pull's scope filter, LogWriter's scope re-emit) is Sprint 2
 * work; this class exists now so config/sync.php's entity registry has
 * something real to reference instead of a dangling class-string.
 */
class OwnerScope implements ScopeDefinition
{
    public function column(): string
    {
        return 'owner_user_id';
    }
}
