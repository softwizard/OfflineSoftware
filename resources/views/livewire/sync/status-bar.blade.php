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
