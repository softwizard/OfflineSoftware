<div>
    @if ($this->count > 0)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
            <flux:text>
                {{ trans_choice(
                    ':count of your changes was superseded by a newer edit elsewhere.|:count of your changes were superseded by a newer edit elsewhere.',
                    $this->count,
                    ['count' => $this->count],
                ) }}
            </flux:text>
            <div class="mt-2">
                <flux:button size="sm" wire:click="acknowledge">{{ __('Got it') }}</flux:button>
            </div>
        </div>
    @endif
</div>
