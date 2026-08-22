<?php

namespace App\Livewire\Sync;

use App\Sync\Connectivity;
use App\Sync\SyncRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * S5-03 — persistent status bar. wire:poll re-renders (and so
 * recomputes these #[Computed] properties fresh each time) rather than
 * triggering a sync attempt itself; actual syncing stays driven by the
 * scheduler (S3-08) or the explicit "Sync Now" button (S5-05) — polling
 * here is purely about keeping the *display* honest, most importantly
 * the online/offline state, which Connectivity itself only re-checks
 * once its own 30s cache expires.
 */
class StatusBar extends Component
{
    #[Computed]
    public function online(): bool
    {
        return app(Connectivity::class)->online();
    }

    #[Computed]
    public function pendingCount(): int
    {
        return DB::table('sync_changes')->where('status', 'pending')->count();
    }

    #[Computed]
    public function lastSuccessAt(): ?string
    {
        return DB::table('sync_state')->where('id', 1)->value('last_success_at');
    }

    public function syncNow(): void
    {
        app(SyncRunner::class)->run();

        unset($this->online, $this->pendingCount, $this->lastSuccessAt);
    }

    public function render(): View
    {
        return view('livewire.sync.status-bar');
    }
}
