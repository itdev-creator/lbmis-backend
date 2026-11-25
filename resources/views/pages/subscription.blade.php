<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My subscription')] class extends Component {
    #[Computed]
    public function subscription(): ?Subscription
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->franchisee?->activeSubscription();
    }

    /**
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()->where('is_active', true)->orderBy('price')->get();
    }

    /**
     * @return array<string, array{label: string, subscription: bool}>
     */
    #[Computed]
    public function modules(): array
    {
        /** @var array<string, array{label: string, subscription: bool}> $modules */
        $modules = config('lbmis.modules');

        return $modules;
    }
}; ?>

<div class="flex w-full max-w-4xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('My subscription') }}</flux:heading>
        <flux:text class="mt-1">{{ auth()->user()->franchisee?->company_name }}</flux:text>
    </div>

    @if (session('module_locked'))
        <flux:callout icon="lock-closed" variant="warning" :heading="__(':module needs a plan that includes it.', ['module' => session('module_locked')])">
            <flux:callout.text>{{ __('Choose a plan below to unlock it.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:card>
        <flux:heading size="lg">{{ __('Current plan') }}</flux:heading>
        @if ($this->subscription)
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $this->subscription->plan->name }}</span>
                <flux:badge size="sm" :color="$this->subscription->status->color()">{{ $this->subscription->status->label() }}</flux:badge>
            </div>
            <flux:text class="mt-1">
                {{ $this->subscription->plan->priceLabel() }}
                @if ($this->subscription->ends_at)
                    · {{ __('until :date', ['date' => $this->subscription->ends_at->format('d M Y')]) }}
                @endif
            </flux:text>
        @else
            <flux:text class="mt-2">{{ __('No active plan. Paid modules are locked.') }}</flux:text>
        @endif

        <div class="mt-4 grid gap-2 sm:grid-cols-2">
            @foreach ($this->modules as $key => $module)
                @php($unlocked = auth()->user()->canUseModule($key))
                <div class="flex items-center gap-2">
                    <flux:icon :name="$unlocked ? 'check-circle' : 'lock-closed'" variant="mini" :class="$unlocked ? 'text-green-600' : 'text-zinc-400'" />
                    <flux:text>{{ $module['label'] }}</flux:text>
                </div>
            @endforeach
        </div>
    </flux:card>

    <div>
        <flux:heading size="lg" class="mb-3">{{ __('Available plans') }}</flux:heading>
        <div class="grid gap-4 md:grid-cols-3">
            @forelse ($this->plans as $plan)
                <flux:card class="flex flex-col">
                    <flux:heading>{{ $plan->name }}</flux:heading>
                    <div class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ $plan->priceLabel() }}</div>
                    @if ($plan->description)
                        <flux:text class="mt-2">{{ $plan->description }}</flux:text>
                    @endif
                    <ul class="mt-3 flex-1 space-y-1">
                        @foreach ((array) $plan->modules as $module)
                            <li class="text-sm text-zinc-700 dark:text-zinc-300">· {{ $this->modules[$module]['label'] ?? $module }}</li>
                        @endforeach
                    </ul>
                    @if ($this->subscription?->plan_id === $plan->id)
                        <flux:button class="mt-4" disabled>{{ __('Current plan') }}</flux:button>
                    @else
                        {{-- Online payment with GKash is the next step; until then the button stays disabled. --}}
                        <flux:button class="mt-4" variant="primary" disabled>{{ __('Pay online (coming soon)') }}</flux:button>
                    @endif
                </flux:card>
            @empty
                <flux:text>{{ __('No plans are available yet.') }}</flux:text>
            @endforelse
        </div>
        <flux:text size="sm" class="mt-3">{{ __('To change plan now, contact the Franchise department.') }}</flux:text>
    </div>
</div>
