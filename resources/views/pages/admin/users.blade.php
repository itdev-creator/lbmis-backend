<?php

use App\Models\Department;
use App\Models\Franchisee;
use App\Models\Outlet;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Users')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $roleFilter = '';

    // Form
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $role = '';
    public ?int $department_id = null;
    public ?int $franchisee_id = null;
    public string $password = '';
    public bool $is_active = true;

    /** @var list<int> Outlets assigned to a staff user */
    public array $outletIds = [];
    public string $outletSearch = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'roleFilter'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function roles(): array
    {
        /** @var array<string, array{label: string}> $roles */
        $roles = config('lbmis.roles');

        return array_map(fn (array $role) => $role['label'], $roles);
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles', 'department', 'franchisee'])
            ->withCount('assignedOutlets')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('name', "%{$this->search}%")
                ->orWhereLike('email', "%{$this->search}%")))
            ->when(array_key_exists($this->roleFilter, $this->roles), fn ($q) => $q->role($this->roleFilter))
            ->orderBy('name')
            ->paginate(25);
    }

    /**
     * @return Collection<int, Department>
     */
    #[Computed]
    public function departments(): Collection
    {
        return Department::query()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Franchisee>
     */
    #[Computed]
    public function franchisees(): Collection
    {
        return Franchisee::query()->orderBy('company_name')->get();
    }

    /**
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function selectedOutlets(): Collection
    {
        return Outlet::query()->whereIn('id', $this->outletIds)->orderBy('code')->get();
    }

    /**
     * Up to 20 outlets matching the search, not yet assigned.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outletOptions(): Collection
    {
        if (Str::length(trim($this->outletSearch)) < 2) {
            return new Collection;
        }

        return Outlet::query()
            ->whereNotIn('id', $this->outletIds)
            ->where(fn ($q) => $q
                ->whereLike('code', "%{$this->outletSearch}%")
                ->orWhereLike('name', "%{$this->outletSearch}%"))
            ->orderBy('code')
            ->limit(20)
            ->get();
    }

    public function addOutlet(int $id): void
    {
        if (! in_array($id, $this->outletIds, true)) {
            $this->outletIds[] = $id;
        }
        $this->outletSearch = '';
    }

    public function removeOutlet(int $id): void
    {
        $this->outletIds = array_values(array_filter($this->outletIds, fn (int $outletId) => $outletId !== $id));
    }

    public function create(): void
    {
        Gate::authorize('users.manage');
        $this->resetForm();
        Flux::modal('user-form')->show();
    }

    public function edit(int $id): void
    {
        Gate::authorize('users.manage');
        $user = User::query()->with('assignedOutlets')->findOrFail($id);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = (string) $user->phone;
        $this->role = (string) $user->roleName();
        $this->department_id = $user->department_id;
        $this->franchisee_id = $user->franchisee_id;
        $this->is_active = $user->is_active;
        $this->outletIds = $user->assignedOutlets->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        Flux::modal('user-form')->show();
    }

    public function save(): void
    {
        Gate::authorize('users.manage');

        $this->email = Str::lower(trim($this->email));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(array_keys($this->roles))],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'franchisee_id' => $this->role === 'franchise-owner'
                ? ['required', 'integer', 'exists:franchisees,id']
                : ['nullable'],
            'password' => $this->editingId === null
                ? ['required', 'string', Password::default()]
                : ['nullable', 'string', Password::default()],
            'is_active' => ['boolean'],
        ], [
            'franchisee_id.required' => __('A franchise owner must belong to a franchisee company.'),
        ]);

        /** @var User $me */
        $me = Auth::user();
        if ($this->editingId === $me->id && ($validated['role'] !== 'admin' || ! $validated['is_active'])) {
            $this->addError('role', __('You cannot remove your own admin role or deactivate yourself.'));

            return;
        }

        $user = $this->editingId !== null ? User::query()->findOrFail($this->editingId) : new User;

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
            'department_id' => $validated['department_id'],
            'franchisee_id' => $validated['role'] === 'franchise-owner' ? $validated['franchisee_id'] : null,
            'is_active' => $validated['is_active'],
        ]);

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        // Accounts are created by an admin, so the email counts as verified.
        if (! $user->exists) {
            $user->email_verified_at = now();
        }

        $user->save();
        $user->syncRoles([$validated['role']]);
        $user->assignedOutlets()->sync($validated['role'] === 'staff' ? $this->outletIds : []);

        Flux::modal('user-form')->close();
        Flux::toast(variant: 'success', text: __('User saved.'));
        $this->resetForm();
        unset($this->users);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset([
            'editingId', 'name', 'email', 'phone', 'role', 'department_id', 'franchisee_id',
            'password', 'is_active', 'outletIds', 'outletSearch',
        ]);
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Each user has one role. The role decides which pages and outlets they can see.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New user') }}</flux:button>
    </div>

    <div class="grid gap-3 md:grid-cols-3">
        <div class="md:col-span-2">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search name or email')" />
        </div>
        <flux:select wire:model.live="roleFilter">
            <flux:select.option value="">{{ __('All roles') }}</flux:select.option>
            @foreach ($this->roles as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->users">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column>{{ __('Department / franchisee') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->users as $user)
                <flux:table.row :key="$user->id">
                    <flux:table.cell>
                        <div class="font-medium">{{ $user->name }}</div>
                        <flux:text size="sm">{{ $user->email }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $user->roleLabel() }}
                        @if ($user->hasRole('staff'))
                            <flux:text size="sm">{{ trans_choice(':count outlet|:count outlets', $user->assigned_outlets_count, ['count' => $user->assigned_outlets_count]) }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $user->franchisee?->company_name ?? $user->department?->name ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($user->is_active)
                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $user->id }})">{{ __('Edit') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="user-form" class="w-full md:w-[36rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Edit user') : __('New user') }}</flux:heading>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:input wire:model="email" type="email" :label="__('Email')" required />
                <flux:input wire:model="phone" :label="__('Phone')" />

                <flux:select wire:model.live="role" :label="__('Role')" :placeholder="__('Choose role...')">
                    @foreach ($this->roles as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($role === 'franchise-owner')
                    <div class="md:col-span-2">
                        <flux:select wire:model="franchisee_id" :label="__('Franchisee company')" :placeholder="__('Choose franchisee...')">
                            @foreach ($this->franchisees as $franchisee)
                                <flux:select.option :value="$franchisee->id">{{ $franchisee->company_name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @else
                    <div class="md:col-span-2">
                        <flux:select wire:model="department_id" :label="__('Department')" :placeholder="__('No department')">
                            @foreach ($this->departments as $department)
                                <flux:select.option :value="$department->id">{{ $department->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endif

                @if ($role === 'staff')
                    <div class="space-y-2 md:col-span-2">
                        <flux:input wire:model.live.debounce.300ms="outletSearch" :label="__('Assigned outlets')" icon="magnifying-glass" :placeholder="__('Type an outlet code or name to add')" />

                        @if ($this->outletOptions->isNotEmpty())
                            <div class="max-h-40 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                                @foreach ($this->outletOptions as $option)
                                    <button type="button" wire:click="addOutlet({{ $option->id }})"
                                            class="block w-full px-3 py-1.5 text-start text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700">
                                        {{ $option->code }} · {{ $option->name }}
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex flex-wrap gap-1.5">
                            @forelse ($this->selectedOutlets as $outlet)
                                <flux:badge size="sm" wire:key="assigned-{{ $outlet->id }}">
                                    {{ $outlet->code }}
                                    <flux:badge.close wire:click="removeOutlet({{ $outlet->id }})" />
                                </flux:badge>
                            @empty
                                <flux:text size="sm">{{ __('No outlets assigned: this user will see no outlets.') }}</flux:text>
                            @endforelse
                        </div>
                    </div>
                @endif

                <div class="md:col-span-2">
                    <flux:input wire:model="password" type="password" :label="$editingId ? __('New password (leave blank to keep)') : __('Password')" autocomplete="new-password" viewable />
                </div>

                <div class="md:col-span-2">
                    <flux:switch wire:model="is_active" :label="__('Active (can sign in)')" />
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
</div>
