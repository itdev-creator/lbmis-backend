<?php

use App\Models\Brand;
use App\Models\Department;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Departments & brands')] class extends Component {
    public ?int $departmentId = null;
    public string $departmentName = '';
    public string $departmentCode = '';

    public ?int $brandId = null;
    public string $brandName = '';

    /**
     * @return Collection<int, Department>
     */
    #[Computed]
    public function departments(): Collection
    {
        return Department::query()->withCount('users')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Brand>
     */
    #[Computed]
    public function brands(): Collection
    {
        return Brand::query()->withCount('outlets')->orderBy('name')->get();
    }

    public function editDepartment(?int $id = null): void
    {
        Gate::authorize('departments.manage');
        $this->resetValidation();
        $department = $id !== null ? Department::query()->findOrFail($id) : null;

        $this->departmentId = $department?->id;
        $this->departmentName = $department->name ?? '';
        $this->departmentCode = $department->code ?? '';

        Flux::modal('department-form')->show();
    }

    public function saveDepartment(): void
    {
        Gate::authorize('departments.manage');
        $this->departmentCode = strtoupper(trim($this->departmentCode));

        $validated = $this->validate([
            'departmentName' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->ignore($this->departmentId)],
            'departmentCode' => ['required', 'alpha_dash', 'max:20', Rule::unique('departments', 'code')->ignore($this->departmentId)],
        ]);

        $department = $this->departmentId !== null
            ? Department::query()->findOrFail($this->departmentId)
            : new Department;
        $department->fill(['name' => $validated['departmentName'], 'code' => $validated['departmentCode']])->save();

        Flux::modal('department-form')->close();
        Flux::toast(variant: 'success', text: __('Department saved.'));
        unset($this->departments);
    }

    public function deleteDepartment(int $id): void
    {
        Gate::authorize('departments.manage');
        $department = Department::query()->withCount('users')->findOrFail($id);

        if ($department->users_count > 0) {
            Flux::toast(variant: 'danger', text: __('Move its users to another department first.'));

            return;
        }

        $department->delete();
        unset($this->departments);
    }

    public function editBrand(?int $id = null): void
    {
        Gate::authorize('departments.manage');
        $this->resetValidation();
        $brand = $id !== null ? Brand::query()->findOrFail($id) : null;

        $this->brandId = $brand?->id;
        $this->brandName = $brand->name ?? '';

        Flux::modal('brand-form')->show();
    }

    public function saveBrand(): void
    {
        Gate::authorize('departments.manage');

        $validated = $this->validate([
            'brandName' => ['required', 'string', 'max:255', Rule::unique('brands', 'name')->ignore($this->brandId)],
        ]);

        $brand = $this->brandId !== null ? Brand::query()->findOrFail($this->brandId) : new Brand;
        $brand->fill(['name' => $validated['brandName']])->save();

        Flux::modal('brand-form')->close();
        Flux::toast(variant: 'success', text: __('Brand saved.'));
        unset($this->brands);
    }
}; ?>

<div class="flex w-full flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Departments & brands') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Lists used across the system. Departments group internal users; brands group outlets.') }}</flux:text>
    </div>

    <section class="space-y-3">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('Departments') }}</flux:heading>
            <flux:button size="sm" icon="plus" wire:click="editDepartment">{{ __('New department') }}</flux:button>
        </div>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Code') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Users') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->departments as $department)
                    <flux:table.row :key="'d'.$department->id">
                        <flux:table.cell class="font-medium">{{ $department->code }}</flux:table.cell>
                        <flux:table.cell>{{ $department->name }}</flux:table.cell>
                        <flux:table.cell>{{ $department->users_count }}</flux:table.cell>
                        <flux:table.cell class="text-end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editDepartment({{ $department->id }})">{{ __('Edit') }}</flux:button>
                            @if ($department->users_count === 0)
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteDepartment({{ $department->id }})"
                                             wire:confirm="{{ __('Delete this department?') }}">{{ __('Delete') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">
                            <flux:text>{{ __('No departments yet. Run the DepartmentSeeder or add them here.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="space-y-3">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('Brands') }}</flux:heading>
            <flux:button size="sm" icon="plus" wire:click="editBrand">{{ __('New brand') }}</flux:button>
        </div>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Outlets') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->brands as $brand)
                    <flux:table.row :key="'b'.$brand->id">
                        <flux:table.cell class="font-medium">{{ $brand->name }}</flux:table.cell>
                        <flux:table.cell>{{ $brand->outlets_count }}</flux:table.cell>
                        <flux:table.cell class="text-end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editBrand({{ $brand->id }})">{{ __('Edit') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3"><flux:text>{{ __('No brands yet.') }}</flux:text></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <flux:modal name="department-form" class="w-full md:w-96">
        <form wire:submit="saveDepartment" class="space-y-5">
            <flux:heading size="lg">{{ $departmentId ? __('Edit department') : __('New department') }}</flux:heading>
            <flux:input wire:model="departmentName" :label="__('Name')" required />
            <flux:input wire:model="departmentCode" :label="__('Short code')" :description="__('Letters, numbers, dashes; e.g. TECH')" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="brand-form" class="w-full md:w-96">
        <form wire:submit="saveBrand" class="space-y-5">
            <flux:heading size="lg">{{ $brandId ? __('Edit brand') : __('New brand') }}</flux:heading>
            <flux:input wire:model="brandName" :label="__('Name')" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
