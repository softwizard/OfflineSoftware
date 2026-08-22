<div>
    <div wire:poll.10s class="flex items-center gap-4 border-b border-neutral-200 bg-neutral-50 px-4 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
        <span class="flex items-center gap-1.5">
            <span class="h-2 w-2 rounded-full {{ $this->online ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
            {{ $this->online ? __('Online') : __('Offline') }}
        </span>

        @if ($this->pendingCount > 0)
            <flux:text class="text-amber-700 dark:text-amber-400">
                {{ trans_choice('{1} :count change pending|[2,*] :count changes pending', $this->pendingCount, ['count' => $this->pendingCount]) }}
            </flux:text>
        @endif

        <flux:text class="text-xs text-neutral-500">
            {{ $this->lastSuccessAt ? __('Last synced :time', ['time' => \Illuminate\Support\Carbon::parse($this->lastSuccessAt)->diffForHumans()]) : __('Never synced') }}
        </flux:text>

        <flux:spacer />

        <flux:button size="sm" wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow">
            <span wire:loading.remove wire:target="syncNow">{{ __('Sync Now') }}</span>
            <span wire:loading wire:target="syncNow">{{ __('Syncing…') }}</span>
        </flux:button>
    </div>

    @if ($this->offlineLock['locked'])
        <div class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ __("Writes are locked — this device hasn't synced in :days days (limit :limit). Connect to the network and press Sync Now to unlock.", ['days' => $this->offlineLock['days_offline'], 'limit' => \App\Sync\OfflineLock::MAX_OFFLINE_DAYS]) }}
        </div>
    @elseif ($this->offlineLock['warning'])
        <div class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            {{ trans_choice(
                'Writes lock in {1} :count day if this device doesn\'t sync|[2,*] Writes lock in :count days if this device doesn\'t sync',
                $this->offlineLock['days_remaining'],
                ['count' => $this->offlineLock['days_remaining']]
            ) }}
        </div>
    @endif
</div>
