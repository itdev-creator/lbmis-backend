<?php

use App\Enums\SubscriptionStatus;
use App\Models\Franchisee;
use App\Models\Plan;
use App\Models\Subscription;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Franchisees')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    // Company form
    public ?int $editingId = null;
    public string $company_name = '';
    public string $registration_no = '';
    public string $contact_name = '';
    public string $email = '';
    public string $phone = '';
    public string $address = '';
    public string $autocount_debtor_code = '';
    public bool $is_active = true;

    // Plan form
    public ?int $planFranchiseeId = null;
    public ?int $plan_id = null;
    public ?string $starts_at = null;
    public ?string $ends_at = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Franchisee>
     */
    #[Computed]
    public function franchisees(): LengthAwarePaginator
    {
        return Franchisee::query()
            ->withCount('outlets')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('company_name', "%{$this->search}%")
                ->orWhereLike('contact_name', "%{$this->search}%")
                ->orWhereLike('autocount_debtor_code', "%{$this->search}%")))
            ->orderBy('company_name')
            ->paginate(25);
    }

    /**
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()->where('is_active', true)->orderBy('price')->get();
    }

    public function edit(?int $id = null): void
    {
        Gate::authorize('franchisees.manage');
        $this->resetValidation();
        $this->reset(['editingId', 'company_name', 'registration_no', 'contact_name', 'email', 'phone', 'address', 'autocount_debtor_code', 'is_active']);

        if ($id !== null) {
            $franchisee = Franchisee::query()->findOrFail($id);
            $this->editingId = $franchisee->id;
            $this->company_name = $franchisee->company_name;
            $this->registration_no = (string) $franchisee->registration_no;
            $this->contact_name = (string) $franchisee->contact_name;
            $this->email = (string) $franchisee->email;
            $this->phone = (string) $franchisee->phone;
            $this->address = (string) $franchisee->address;
            $this->autocount_debtor_code = (string) $franchisee->autocount_debtor_code;
            $this->is_active = $franchisee->is_active;
        }

        Flux::modal('franchisee-form')->show();
    }

    public function save(): void
    {
        Gate::authorize('franchisees.manage');

        $validated = $this->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'registration_no' => ['nullable', 'string', 'max:50'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'autocount_debtor_code' => ['nullable', 'string', 'max:30', Rule::unique('franchisees', 'autocount_debtor_code')->ignore($this->editingId)],
            'is_active' => ['boolean'],
        ]);

        // Empty text fields are stored as NULL.
        $data = array_map(fn ($value) => $value === '' ? null : $value, $validated);

        $franchisee = $this->editingId !== null ? Franchisee::query()->findOrFail($this->editingId) : new Franchisee;
        $franchisee->fill($data)->save();

        Flux::modal('franchisee-form')->close();
        Flux::toast(variant: 'success', text: __('Franchisee saved.'));
        unset($this->franchisees);
    }

    public function choosePlan(int $id): void
    {
        Gate::authorize('subscriptions.manage');
        $this->resetValidation();
        $current = Franchisee::query()->findOrFail($id)->activeSubscription();

        $this->planFranchiseeId = $id;
        $this->plan_id = $current?->plan_id;
        $this->starts_at = now()->toDateString();
        $this->ends_at = now()->addMonth()->toDateString();

        Flux::modal('plan-form')->show();
    }

    /**
     * Manual plan assignment (e.g. paid offline). Online payment through GKash comes later.
     */
    public function savePlan(): void
    {
        Gate::authorize('subscriptions.manage');

        $validated = $this->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
        ]);

        $franchiseeId = (int) $this->planFranchiseeId;
        Franchisee::query()->findOrFail($franchiseeId);

        DB::transaction(function () use ($validated, $franchiseeId) {
            Subscription::query()
                ->where('franchisee_id', $franchiseeId)
                ->where('status', SubscriptionStatus::Active->value)
                ->update(['status' => SubscriptionStatus::Cancelled->value]);

            Subscription::query()->create([
                'franchisee_id' => $franchiseeId,
                'plan_id' => $validated['plan_id'],
                'status' => SubscriptionStatus::Active->value,
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'],
                'source' => 'manual',
            ]);
        });

        Flux::modal('plan-form')->close();
        Flux::toast(variant: 'success', text: __('Plan assigned.'));
        unset($this->franchisees);
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Franchisees') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Companies that own franchise and Alacart outlets.') }}</flux:text>
        </div>
        @can('franchisees.manage')
            <flux:button variant="primary" icon="plus" wire:click="edit">{{ __('New franchisee') }}</flux:button>
        @endcan
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search company, contact or debtor code')" class="max-w-md" />

    <flux:table :paginate="$this->franchisees">
        <flux:table.columns>
            <flux:table.column>{{ __('Company') }}</flux:table.column>
            <flux:table.column>{{ __('Contact') }}</flux:table.column>
            <flux:table.column>{{ __('Outlets') }}</flux:table.column>
            <flux:table.column>{{ __('Plan') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->franchisees as $franchisee)
                @php($subscription = $franchisee->activeSubscription())
                <flux:table.row :key="$franchisee->id">
                    <flux:table.cell>
                        <div class="font-medium">{{ $franchisee->company_name }}</div>
                        <flux:text size="sm">
                            {{ $franchisee->autocount_debtor_code ?? __('No debtor code') }}
                            @unless ($franchisee->is_active) · {{ __('Inactive') }} @endunless
                        </flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $franchisee->contact_name ?? '—' }}
                        <flux:text size="sm">{{ $franchisee->phone }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>{{ $franchisee->outlets_count }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($subscription)
                            <flux:badge size="sm" color="green">{{ $subscription->plan->name }}</flux:badge>
                            @if ($subscription->ends_at)
                                <flux:text size="sm">{{ __('until :date', ['date' => $subscription->ends_at->format('d M Y')]) }}</flux:text>
                            @endif
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('No plan') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-end">
                        @can('subscriptions.manage')
                            <flux:button size="sm" variant="ghost" icon="credit-card" wire:click="choosePlan({{ $franchisee->id }})">{{ __('Plan') }}</flux:button>
                        @endcan
                        @can('franchisees.manage')
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $franchisee->id }})">{{ __('Edit') }}</flux:button>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5"><flux:text>{{ __('No franchisees yet.') }}</flux:text></flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @can('franchisees.manage')
        <flux:modal name="franchisee-form" class="w-full md:w-[36rem]">
            <form wire:submit="save" class="space-y-5">
                <flux:heading size="lg">{{ $editingId ? __('Edit franchisee') : __('New franchisee') }}</flux:heading>
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2"><flux:input wire:model="company_name" :label="__('Company name')" required /></div>
                    <flux:input wire:model="registration_no" :label="__('SSM registration no.')" />
                    <flux:input wire:model="autocount_debtor_code" :label="__('AutoCount debtor code')" />
                    <flux:input wire:model="contact_name" :label="__('Contact person')" />
                    <flux:input wire:model="phone" :label="__('Phone')" />
                    <div class="md:col-span-2"><flux:input wire:model="email" type="email" :label="__('Email')" /></div>
                    <div class="md:col-span-2"><flux:textarea wire:model="address" :label="__('Address')" rows="2" /></div>
                    <div class="md:col-span-2"><flux:switch wire:model="is_active" :label="__('Active')" /></div>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    @can('subscriptions.manage')
        <flux:modal name="plan-form" class="w-full md:w-96">
            <form wire:submit="savePlan" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Assign plan') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('For payments received outside the system. Replaces the current plan.') }}</flux:text>
                </div>
                <flux:select wire:model="plan_id" :label="__('Plan')" :placeholder="__('Choose plan...')">
                    @foreach ($this->plans as $plan)
                        <flux:select.option :value="$plan->id">{{ $plan->name }} · {{ $plan->priceLabel() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="grid grid-cols-2 gap-3">
                    <flux:input wire:model="starts_at" type="date" :label="__('Starts')" />
                    <flux:input wire:model="ends_at" type="date" :label="__('Ends')" />
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Assign') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
