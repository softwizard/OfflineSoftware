<?php

namespace App\Livewire\Sync;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * S4-04 — "the user is told, never silently overridden." A pushed change
 * that lost a conflict stays in sync_changes with status='conflict'
 * (PushRunner never deletes those) until the user has actually seen this
 * notice, not just until the next page load.
 */
class SupersededNotice extends Component
{
    #[Computed]
    public function count(): int
    {
        if (! sync_is_local()) {
            return 0;
        }

        return DB::table('sync_changes')
            ->where('status', 'conflict')
            ->whereNull('acknowledged_at')
            ->count();
    }

    public function acknowledge(): void
    {
        DB::table('sync_changes')
            ->where('status', 'conflict')
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]);

        unset($this->count);
    }

    public function render(): View
    {
        return view('livewire.sync.superseded-notice');
    }
}
