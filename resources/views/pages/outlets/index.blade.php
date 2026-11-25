<?php

use App\Enums\OutletStage;
use App\Enums\OutletType;
use App\Models\Brand;
use App\Models\Franchisee;
use App\Models\Outlet;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Outlets')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $stage = '';

    // Form
    public ?int $editingId = null;
    public string $code = '';
    public string $name = '';
    public ?int $brand_id = null;
    public string $outlet_type = 'own';
    public ?int $franchisee_id = null;
    public string $outlet_stage = 'pipeline';
    public string $address = '';
    public string $state = '';
    public ?string $latitude = null;
    public ?string $longitude = null;
    public ?string $opened_at = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'stage'], true)) {
            $this->resetPage();
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * @return LengthAwarePaginator<int, Outlet>
     */
    #[Computed]
    public function outlets(): LengthAwarePaginator
    {
        return Outlet::query()
            ->visibleTo($this->user())
            ->with(['brand', 'franchisee'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('code', "%{$this->search}%")
                ->orWhereLike('name', "%{$this->search}%")
                ->orWhereLike('state', "%{$this->search}%")))
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->stage !== '', fn ($q) => $q->where('stage', $this->stage))
            ->orderBy('code')
            ->paginate(25);
    }

    /**
     * @return Collection<int, Brand>
     */
    #[Computed]
    public function brands(): Collection
    {
        return Brand::query()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Franchisee>
     */
    #[Computed]
    public function franchisees(): Collection
    {
        return Franchisee::query()->where('is_active', true)->orderBy('company_name')->get();
    }

    public function create(): void
    {
        Gate::authorize('outlets.manage');
        $this->resetForm();
        Flux::modal('outlet-form')->show();
    }

    public function edit(int $id): void
    {
        Gate::authorize('outlets.manage');
        $outlet = Outlet::query()->visibleTo($this->user())->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $outlet->id;
        $this->code = $outlet->code;
        $this->name = $outlet->name;
        $this->brand_id = $outlet->brand_id;
        $this->outlet_type = $outlet->type->value;
        $this->franchisee_id = $outlet->franchisee_id;
        $this->outlet_stage = $outlet->stage->value;
        $this->address = (string) $outlet->address;
        $this->state = (string) $outlet->state;
        $this->latitude = $outlet->latitude !== null ? (string) $outlet->latitude : null;
        $this->longitude = $outlet->longitude !== null ? (string) $outlet->longitude : null;
        $this->opened_at = $outlet->opened_at?->toDateString();

        Flux::modal('outlet-form')->show();
    }

    public function save(): void
    {
        Gate::authorize('outlets.manage');

        $isFranchise = OutletType::tryFrom($this->outlet_type)?->isFranchise() ?? false;

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('outlets', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'outlet_type' => ['required', Rule::enum(OutletType::class)],
            'franchisee_id' => $isFranchise
                ? ['required', 'integer', 'exists:franchisees,id']
                : ['nullable'],
            'outlet_stage' => ['required', Rule::enum(OutletStage::class)],
            'address' => ['nullable', 'string', 'max:1000'],
            'state' => ['nullable', 'string', 'max:50'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'opened_at' => ['nullable', 'date'],
        ], [
            'franchisee_id.required' => __('Franchise and Alacart outlets need a franchisee.'),
        ]);

        $data = [
            'code' => strtoupper(trim($validated['code'])),
            'name' => $validated['name'],
            'brand_id' => $validated['brand_id'],
            'type' => $validated['outlet_type'],
            // Corporate outlets (own, JV) never have a franchisee.
            'franchisee_id' => $isFranchise ? $validated['franchisee_id'] : null,
            'stage' => $validated['outlet_stage'],
            'address' => $validated['address'] ?: null,
            'state' => $validated['state'] ?: null,
            'latitude' => $validated['latitude'] ?: null,
            'longitude' => $validated['longitude'] ?: null,
            'opened_at' => $validated['opened_at'] ?: null,
        ];

        if ($this->editingId !== null) {
            Outlet::query()->visibleTo($this->user())->findOrFail($this->editingId)->update($data);
        } else {
            Outlet::query()->create($data);
        }

        Flux::modal('outlet-form')->close();
        Flux::toast(variant: 'success', text: __('Outlet saved.'));
        $this->resetForm();
        unset($this->outlets);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset([
            'editingId', 'code', 'name', 'brand_id', 'outlet_type', 'franchisee_id', 'outlet_stage',
            'address', 'state', 'latitude', 'longitude', 'opened_at',
        ]);
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Outlets') }}</flux:heading>
            <flux:text class="mt-1">{{ trans_choice(':count outlet|:count outlets', $this->outlets->total(), ['count' => number_format($this->outlets->total())]) }}</flux:text>
        </div>
        @can('outlets.manage')
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New outlet') }}</flux:button>
        @endcan
    </div>

    <div class="grid gap-3 md:grid-cols-4">
        <div class="md:col-span-2">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search code, name or state')" />
        </div>
        <flux:select wire:model.live="type">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            @foreach (\App\Enums\OutletType::options() as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="stage">
            <flux:select.option value="">{{ __('All stages') }}</flux:select.option>
            @foreach (\App\Enums\OutletStage::options() as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->outlets">
        <flux:table.columns>
            <flux:table.column>{{ __('Code') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Franchisee') }}</flux:table.column>
            <flux:table.column>{{ __('State') }}</flux:table.column>
            <flux:table.column>{{ __('Stage') }}</flux:table.column>
            @can('outlets.manage')
                <flux:table.column></flux:table.column>
            @endcan
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->outlets as $outlet)
                <flux:table.row :key="$outlet->id">
                    <flux:table.cell class="font-medium">{{ $outlet->code }}</flux:table.cell>
                    <flux:table.cell>{{ $outlet->name }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $outlet->type->label() }}
                        <flux:text size="sm">{{ $outlet->type->category() }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>{{ $outlet->franchisee?->company_name ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $outlet->state ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$outlet->stage->color()">{{ $outlet->stage->label() }}</flux:badge>
                    </flux:table.cell>
                    @can('outlets.manage')
                        <flux:table.cell class="text-end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $outlet->id }})">{{ __('Edit') }}</flux:button>
                        </flux:table.cell>
                    @endcan
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">
                        <flux:text>{{ __('No outlets match.') }}</flux:text>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @can('outlets.manage')
        <flux:modal name="outlet-form" class="w-full md:w-[40rem]">
            <form wire:submit="save" class="space-y-5">
                <flux:heading size="lg">{{ $editingId ? __('Edit outlet') : __('New outlet') }}</flux:heading>

                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="code" :label="__('Outlet code')" required />
                    <flux:input wire:model="name" :label="__('Outlet name')" required />

                    <flux:select wire:model.live="outlet_type" :label="__('Type')">
                        @foreach (\App\Enums\OutletType::options() as $value => $label)
                            <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="outlet_stage" :label="__('Stage')">
                        @foreach (\App\Enums\OutletStage::options() as $value => $label)
                            <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if (\App\Enums\OutletType::tryFrom($outlet_type)?->isFranchise())
                        <div class="md:col-span-2">
                            <flux:select wire:model="franchisee_id" :label="__('Franchisee')" :placeholder="__('Choose franchisee...')">
                                @foreach ($this->franchisees as $franchisee)
                                    <flux:select.option :value="$franchisee->id">{{ $franchisee->company_name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    @endif

                    <flux:select wire:model="brand_id" :label="__('Brand')" :placeholder="__('No brand')">
                        @foreach ($this->brands as $brand)
                            <flux:select.option :value="$brand->id">{{ $brand->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="opened_at" type="date" :label="__('Opened on')" />

                    <div class="md:col-span-2">
                        <flux:textarea wire:model="address" :label="__('Address')" rows="2" />
                    </div>

                    <flux:input wire:model="state" :label="__('State')" />
                    <div class="grid grid-cols-2 gap-2">
                        <flux:input wire:model="latitude" :label="__('Latitude')" inputmode="decimal" />
                        <flux:input wire:model="longitude" :label="__('Longitude')" inputmode="decimal" />
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
