<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sync Status')] class extends Component {
    public function mount(): void
    {
        abort_unless(sync_is_local(), 404);
    }

    public function acknowledge(string $changeId): void
    {
        DB::table('sync_changes')->where('id', $changeId)->update(['acknowledged_at' => now()]);
    }

    /**
     * S5-04 — "a user can see their 14 queued changes." Every row still
     * in sync_changes at all is, by construction, pending/conflict
     * /rejected — PushRunner deletes 'applied' rows outright (S3-04),
     * so there's nothing to filter out here.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'changes' => DB::table('sync_changes')->orderByDesc('created_at')->get(),
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Sync Status') }}</flux:heading>
    <flux:subheading>{{ __('Everything still queued to reach the server, and why anything didn\'t make it.') }}</flux:subheading>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-neutral-200 dark:border-neutral-700">
                    <th class="py-2 pr-4 font-medium">{{ __('Entity') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Op') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Age') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Attempts') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Error') }}</th>
                    <th class="py-2 pr-4 font-medium"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($changes as $change)
                    <tr wire:key="change-{{ $change->id }}" class="border-b border-neutral-100 dark:border-neutral-800">
                        <td class="py-2 pr-4">{{ $change->entity }}</td>
                        <td class="py-2 pr-4">{{ $change->op }}</td>
                        <td class="py-2 pr-4">{{ \Illuminate\Support\Carbon::parse($change->created_at)->diffForHumans() }}</td>
                        <td class="py-2 pr-4">{{ $change->attempts }}</td>
                        <td class="py-2 pr-4">
                            <span class="rounded px-2 py-0.5 text-xs font-medium
                                @if ($change->status === 'pending') bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200
                                @elseif ($change->status === 'conflict') bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-200
                                @else bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200
                                @endif">
                                {{ ucfirst($change->status) }}
                            </span>
                        </td>
                        <td class="py-2 pr-4 text-xs text-neutral-500">{{ $change->error }}</td>
                        <td class="py-2 pr-4">
                            @if (in_array($change->status, ['conflict', 'rejected'], true) && ! $change->acknowledged_at)
                                <flux:button size="sm" variant="ghost" wire:click="acknowledge('{{ $change->id }}')">
                                    {{ __('Acknowledge') }}
                                </flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-6 text-center text-neutral-500">{{ __('Nothing queued — everything is synced.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
