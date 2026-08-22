<?php

use App\Sync\ConflictRestorer;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sync Conflicts')] class extends Component {
    public function mount(): void
    {
        abort_unless(sync_is_cloud(), 404);
    }

    public function restore(string $conflictId): void
    {
        $conflict = DB::table('sync_conflicts')->where('id', $conflictId)->first();

        abort_if(! $conflict, 404);

        app(ConflictRestorer::class)->restore($conflict);

        Flux::toast(variant: 'success', text: __('Restored — this will reach every device on its next pull.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'conflicts' => DB::table('sync_conflicts')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading size="xl">{{ __('Sync Conflicts') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Every conflict below was already resolved automatically. Restore reverses that decision and propagates to every device on its next pull.') }}
            </flux:text>
        </div>

        @if ($conflicts->isEmpty())
            <flux:text>{{ __('No conflicts recorded.') }}</flux:text>
        @else
            <div class="space-y-4">
                @foreach ($conflicts as $conflict)
                    @php
                        $incoming = json_decode($conflict->incoming_payload, true) ?? [];
                        $current = json_decode($conflict->current_payload, true) ?? [];
                    @endphp
                    <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700" wire:key="conflict-{{ $conflict->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:text class="font-mono text-xs">{{ $conflict->entity }} · {{ $conflict->entity_id }}</flux:text>
                                @if ($conflict->clock_suspect)
                                    <flux:text class="rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900 dark:text-red-200">
                                        {{ __('Clock suspect') }}
                                    </flux:text>
                                @endif
                                @if ($conflict->status === 'restored')
                                    <flux:text class="rounded bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900 dark:text-blue-200">
                                        {{ __('Restored') }}
                                    </flux:text>
                                @endif
                            </div>
                            <flux:text class="text-xs">{{ $conflict->created_at }} · {{ __('from device :device', ['device' => $conflict->origin_device]) }}</flux:text>
                        </div>

                        <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="rounded-lg p-3 {{ $conflict->winner === 'incoming' ? 'bg-emerald-50 dark:bg-emerald-950' : 'bg-neutral-50 dark:bg-neutral-800' }}">
                                <flux:heading size="sm">
                                    {{ __('Incoming') }}
                                    @if ($conflict->winner === 'incoming')
                                        <span class="font-normal text-emerald-700 dark:text-emerald-300">({{ __('won') }})</span>
                                    @endif
                                </flux:heading>
                                <pre class="mt-2 overflow-x-auto text-xs">{{ json_encode($incoming, JSON_PRETTY_PRINT) }}</pre>
                            </div>
                            <div class="rounded-lg p-3 {{ $conflict->winner === 'current' ? 'bg-emerald-50 dark:bg-emerald-950' : 'bg-neutral-50 dark:bg-neutral-800' }}">
                                <flux:heading size="sm">
                                    {{ __('Current (canonical)') }}
                                    @if ($conflict->winner === 'current')
                                        <span class="font-normal text-emerald-700 dark:text-emerald-300">({{ __('won') }})</span>
                                    @endif
                                </flux:heading>
                                <pre class="mt-2 overflow-x-auto text-xs">{{ json_encode($current, JSON_PRETTY_PRINT) }}</pre>
                            </div>
                        </div>

                        <div class="mt-3">
                            <flux:button size="sm" wire:click="restore('{{ $conflict->id }}')" wire:confirm="{{ __('Restore the losing value? This overrides the automatic decision for everyone.') }}">
                                {{ __('Restore losing value') }}
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
