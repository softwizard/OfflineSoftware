<?php

use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Sync\OfflineLockException;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Customers')] class extends Component {
    use WithPagination;

    public ?string $editingId = null;

    public string $name = '';

    public ?string $email = null;

    public ?string $phone = null;

    public ?string $city = null;

    public ?string $notes = null;

    public string $status = 'active';

    public function mount(): void
    {
        abort_unless(sync_is_local(), 404);
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('customer-form')->show();
    }

    public function edit(string $id): void
    {
        $customer = Customer::findOrFail($id);

        $this->editingId = $customer->id;
        $this->name = $customer->name;
        $this->email = $customer->email;
        $this->phone = $customer->phone;
        $this->city = $customer->city;
        $this->notes = $customer->notes;
        $this->status = $customer->status;

        Flux::modal('customer-form')->show();
    }

    /**
     * S5-01 — every write in its own transaction: the row write and the
     * outbox insert Syncable triggers underneath it (invariant 1) commit
     * together or not at all.
     */
    public function save(): void
    {
        $validated = $this->validate((new CustomerRequest)->rulesForForm());

        try {
            DB::transaction(function () use ($validated) {
                if ($this->editingId) {
                    Customer::findOrFail($this->editingId)->update($validated);
                } else {
                    Customer::create($validated + ['owner_user_id' => Auth::id()]);
                }
            });
        } catch (OfflineLockException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::modal('customer-form')->close();
        Flux::toast(variant: 'success', text: __('Saved.'));
        $this->resetForm();
    }

    public function delete(string $id): void
    {
        try {
            DB::transaction(function () use ($id) {
                Customer::findOrFail($id)->delete();
            });
        } catch (OfflineLockException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Customer deleted.'));
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'email', 'phone', 'city', 'notes']);
        $this->status = 'active';
        $this->resetErrorBag();
    }

    /**
     * S5-02 — Synced / Pending (n) / Conflict / Rejected, read straight
     * from sync_changes so it can never drift from what PushRunner
     * actually recorded.
     *
     * @return array<string, array{label: string, color: string}>
     */
    private function badges(\Illuminate\Support\Collection $customerIds): array
    {
        $rowsByEntity = DB::table('sync_changes')
            ->where('entity', 'customer')
            ->whereIn('entity_id', $customerIds)
            ->get()
            ->groupBy('entity_id');

        return $customerIds->mapWithKeys(function ($id) use ($rowsByEntity) {
            $rows = $rowsByEntity->get($id, collect());

            if ($rows->isEmpty()) {
                return [$id => ['label' => __('Synced'), 'color' => 'green']];
            }

            $pending = $rows->where('status', 'pending');
            if ($pending->isNotEmpty()) {
                return [$id => ['label' => __('Pending (:count)', ['count' => $pending->count()]), 'color' => 'amber']];
            }

            if ($rows->contains('status', 'conflict')) {
                return [$id => ['label' => __('Conflict'), 'color' => 'orange']];
            }

            return [$id => ['label' => __('Rejected'), 'color' => 'red']];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $customers = Customer::query()->orderByDesc('created_at')->paginate(15);

        return [
            'customers' => $customers,
            'badges' => $this->badges(collect($customers->items())->pluck('id')),
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Customers') }}</flux:heading>
            <flux:subheading>{{ __('Everything here is local — synced_changes tracks what still needs to reach the server.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="create">{{ __('New Customer') }}</flux:button>
    </div>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-neutral-200 dark:border-neutral-700">
                    <th class="py-2 pr-4 font-medium">{{ __('Code') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Name') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Email') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Sync') }}</th>
                    <th class="py-2 pr-4 font-medium"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($customers as $customer)
                    <tr wire:key="customer-{{ $customer->id }}" class="border-b border-neutral-100 dark:border-neutral-800">
                        <td class="py-2 pr-4 font-mono text-xs">{{ $customer->code }}</td>
                        <td class="py-2 pr-4">{{ $customer->name }}</td>
                        <td class="py-2 pr-4">{{ $customer->email }}</td>
                        <td class="py-2 pr-4">{{ $customer->status }}</td>
                        <td class="py-2 pr-4">
                            @php($badge = $badges[$customer->id])
                            <span class="rounded px-2 py-0.5 text-xs font-medium
                                @if ($badge['color'] === 'green') bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200
                                @elseif ($badge['color'] === 'amber') bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200
                                @elseif ($badge['color'] === 'orange') bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-200
                                @else bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200
                                @endif">
                                {{ $badge['label'] }}
                            </span>
                        </td>
                        <td class="py-2 pr-4 text-right">
                            <flux:button size="sm" variant="ghost" wire:click="edit('{{ $customer->id }}')">{{ __('Edit') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="delete('{{ $customer->id }}')" wire:confirm="{{ __('Delete this customer?') }}">
                                {{ __('Delete') }}
                            </flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-6 text-center text-neutral-500">{{ __('No customers yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $customers->links() }}
    </div>

    <flux:modal name="customer-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Customer') : __('New Customer') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required autofocus />
            <flux:input wire:model="email" :label="__('Email')" type="email" />
            <flux:input wire:model="phone" :label="__('Phone')" />
            <flux:input wire:model="city" :label="__('City')" />
            <flux:textarea wire:model="notes" :label="__('Notes')" />
            <flux:select wire:model="status" :label="__('Status')">
                <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
                <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
