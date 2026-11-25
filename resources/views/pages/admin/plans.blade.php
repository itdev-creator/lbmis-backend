<?php

use App\Models\Plan;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plans')] class extends Component {
    public ?int $editingId = null;
    public string $name = '';
    public string $code = '';
    public string $description = '';
    public string $price = '';
    public string $billing_period = 'monthly';
    /** @var list<string> */
    public array $modules = [];
    public bool $is_active = true;

    /**
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()->withCount(['subscriptions as active_count' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('price')->get();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function moduleOptions(): array
    {
        /** @var array<string, array{label: string, subscription: bool}> $modules */
        $modules = config('lbmis.modules');

        return array_map(fn (array $module) => $module['label'], $modules);
    }

    public function edit(?int $id = null): void
    {
        Gate::authorize('plans.manage');
        $this->resetValidation();
        $this->reset(['editingId', 'name', 'code', 'description', 'price', 'billing_period', 'modules', 'is_active']);

        if ($id !== null) {
            $plan = Plan::query()->findOrFail($id);
            $this->editingId = $plan->id;
            $this->name = $plan->name;
            $this->code = $plan->code;
            $this->description = (string) $plan->description;
            $this->price = (string) $plan->price;
            $this->billing_period = $plan->billing_period;
            $this->modules = array_values(array_map('strval', (array) $plan->modules));
            $this->is_active = $plan->is_active;
        }

        Flux::modal('plan-form')->show();
    }

    public function save(): void
    {
        Gate::authorize('plans.manage');
        $this->code = strtoupper(trim($this->code));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('plans', 'code')->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
            'modules' => ['array', 'min:1'],
            'modules.*' => [Rule::in(array_keys($this->moduleOptions))],
            'is_active' => ['boolean'],
        ]);

        $plan = $this->editingId !== null ? Plan::query()->findOrFail($this->editingId) : new Plan;
        $plan->fill([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'description' => $validated['description'] ?: null,
            'price' => $validated['price'],
            'billing_period' => $validated['billing_period'],
            'modules' => array_values($validated['modules']),
            'is_active' => $validated['is_active'],
        ])->save();

        Flux::modal('plan-form')->close();
        Flux::toast(variant: 'success', text: __('Plan saved.'));
        unset($this->plans);
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Plans') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Packages franchise owners subscribe to. A plan unlocks the modules ticked on it.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="edit">{{ __('New plan') }}</flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Plan') }}</flux:table.column>
            <flux:table.column>{{ __('Price') }}</flux:table.column>
            <flux:table.column>{{ __('Modules') }}</flux:table.column>
            <flux:table.column>{{ __('Active subscribers') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->plans as $plan)
                <flux:table.row :key="$plan->id">
                    <flux:table.cell>
                        <div class="font-medium">{{ $plan->name }}</div>
                        <flux:text size="sm">{{ $plan->code }} @unless ($plan->is_active) · {{ __('Hidden') }} @endunless</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>{{ $plan->priceLabel() }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @foreach ((array) $plan->modules as $module)
                                <flux:badge size="sm">{{ $this->moduleOptions[$module] ?? $module }}</flux:badge>
                            @endforeach
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $plan->active_count }}</flux:table.cell>
                    <flux:table.cell class="text-end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $plan->id }})">{{ __('Edit') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5"><flux:text>{{ __('No plans yet.') }}</flux:text></flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="plan-form" class="w-full md:w-[32rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Edit plan') : __('New plan') }}</flux:heading>
            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:input wire:model="code" :label="__('Code')" required />
                <flux:input wire:model="price" :label="__('Price (RM)')" inputmode="decimal" required />
                <flux:select wire:model="billing_period" :label="__('Billed')">
                    <flux:select.option value="monthly">{{ __('Monthly') }}</flux:select.option>
                    <flux:select.option value="yearly">{{ __('Yearly') }}</flux:select.option>
                </flux:select>
                <div class="md:col-span-2">
                    <flux:checkbox.group wire:model="modules" :label="__('Modules included')">
                        @foreach ($this->moduleOptions as $value => $label)
                            <flux:checkbox :value="$value" :label="$label" />
                        @endforeach
                    </flux:checkbox.group>
                </div>
                <div class="md:col-span-2"><flux:textarea wire:model="description" :label="__('Description')" rows="2" /></div>
                <div class="md:col-span-2"><flux:switch wire:model="is_active" :label="__('Available to franchise owners')" /></div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
