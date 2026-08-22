<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * S6-05 — "a device silent for 3 days is visibly flagged." Read-only: the
 * only write action for a device (revoke) is a deliberate CLI-only step
 * (App\Console\Commands\RevokeDevice), not a dashboard button — see that
 * command's docblock for why the token itself is left in place.
 */
new #[Title('Device Fleet')] class extends Component {
    public function mount(): void
    {
        abort_unless(sync_is_cloud(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $devices = Device::orderBy('name')->get();

        $owners = User::whereIn('id', $devices->pluck('owner_user_id')->filter()->unique())
            ->pluck('email', 'id');

        $currentSchemaVersion = (int) config('sync.schema_version');

        $rows = $devices->map(function (Device $device) use ($owners, $currentSchemaVersion) {
            $backlog = $device->owner_user_id
                ? DB::table('sync_log')
                    ->where('target_owner_user_id', $device->owner_user_id)
                    ->where('seq', '>', $device->last_pulled_seq)
                    ->where(fn ($q) => $q->whereNull('origin_device')->orWhere('origin_device', '!=', $device->device_code))
                    ->count()
                : 0;

            return [
                'id' => $device->id,
                'name' => $device->name,
                'device_code' => $device->device_code,
                'owner_email' => $device->owner_user_id ? $owners->get($device->owner_user_id) : null,
                'last_seen_at' => $device->last_seen_at,
                'silent' => ! $device->last_seen_at || $device->last_seen_at->lt(now()->subDays(3)),
                'schema_version' => $device->last_schema_version,
                'schema_stale' => $device->last_schema_version !== null && $device->last_schema_version < $currentSchemaVersion,
                'backlog' => $backlog,
                'revoked' => $device->revoked_at !== null,
            ];
        });

        return [
            'devices' => $rows,
            'unreviewedConflicts' => DB::table('sync_conflicts')->where('status', 'resolved')->count(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <flux:heading size="xl">{{ __('Device Fleet') }}</flux:heading>
                <flux:subheading>{{ __('Last seen, pending backlog, schema version, and open conflicts across every provisioned device.') }}</flux:subheading>
            </div>
            <a href="{{ route('sync.conflicts') }}" wire:navigate>
                <flux:text class="rounded px-3 py-1.5 text-sm font-medium {{ $unreviewedConflicts > 0 ? 'bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-200' : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400' }}">
                    {{ trans_choice('{0} No unreviewed conflicts|{1} :count unreviewed conflict|[2,*] :count unreviewed conflicts', $unreviewedConflicts, ['count' => $unreviewedConflicts]) }}
                </flux:text>
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th class="py-2 pr-4 font-medium">{{ __('Device') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Owner') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Last seen') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Schema') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Backlog') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($devices as $device)
                        <tr wire:key="device-{{ $device['id'] }}" class="border-b border-neutral-100 dark:border-neutral-800">
                            <td class="py-2 pr-4">
                                <div class="font-medium">{{ $device['name'] }}</div>
                                <div class="font-mono text-xs text-neutral-500">{{ $device['device_code'] }}</div>
                            </td>
                            <td class="py-2 pr-4">{{ $device['owner_email'] ?? __('— unassigned —') }}</td>
                            <td class="py-2 pr-4">
                                <span @class(['text-red-600 dark:text-red-400 font-medium' => $device['silent']])>
                                    {{ $device['last_seen_at'] ? $device['last_seen_at']->diffForHumans() : __('Never') }}
                                </span>
                                @if ($device['silent'])
                                    <flux:text class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900 dark:text-red-200">
                                        {{ __('Silent 3+ days') }}
                                    </flux:text>
                                @endif
                            </td>
                            <td class="py-2 pr-4">
                                <span @class(['text-amber-600 dark:text-amber-400' => $device['schema_stale']])>
                                    {{ $device['schema_version'] ?? __('—') }}
                                </span>
                            </td>
                            <td class="py-2 pr-4">{{ $device['backlog'] }}</td>
                            <td class="py-2 pr-4">
                                @if ($device['revoked'])
                                    <flux:text class="rounded bg-neutral-200 px-2 py-0.5 text-xs font-medium text-neutral-700 dark:bg-neutral-700 dark:text-neutral-200">
                                        {{ __('Revoked') }}
                                    </flux:text>
                                @else
                                    <flux:text class="rounded bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200">
                                        {{ __('Active') }}
                                    </flux:text>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-neutral-500">{{ __('No devices provisioned yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
