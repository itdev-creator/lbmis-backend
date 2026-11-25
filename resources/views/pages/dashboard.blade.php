<?php

use App\Enums\OutletStage;
use App\Models\Outlet;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * Outlet count per stage, limited to the outlets this user may see.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function stageCounts(): array
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->can('outlets.view')) {
            return [];
        }

        $counts = Outlet::query()->visibleTo($user)->toBase()
            ->selectRaw('stage, count(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

        $result = [];
        foreach (OutletStage::cases() as $stage) {
            $result[$stage->value] = (int) ($counts[$stage->value] ?? 0);
        }

        return $result;
    }

    #[Computed]
    public function subscription(): ?Subscription
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->isFranchiseOwner() ? $user->franchisee?->activeSubscription() : null;
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => auth()->user()->name]) }}</flux:heading>
        <flux:text class="mt-1">{{ auth()->user()->roleLabel() }}@if (auth()->user()->department) · {{ auth()->user()->department->name }}@endif</flux:text>
    </div>

    @if (auth()->user()->roleName() === null)
        <flux:callout icon="exclamation-triangle" variant="warning" :heading="__('No role assigned yet')">
            <flux:callout.text>{{ __('Ask an LBMIS admin to give your account a role.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @can('outlets.view')
        <div>
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Outlets by stage') }}</flux:heading>
                <flux:button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('outlets.index')" wire:navigate>
                    {{ __('All outlets') }}
                </flux:button>
            </div>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                @foreach (\App\Enums\OutletStage::cases() as $stage)
                    <a href="{{ route('outlets.index', ['stage' => $stage->value]) }}" wire:navigate
                       class="rounded-xl border border-zinc-200 p-4 transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-700/50">
                        <flux:text>{{ $stage->label() }}</flux:text>
                        <div class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white">
                            {{ number_format($this->stageCounts[$stage->value] ?? 0) }}
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endcan

    @if (auth()->user()->isFranchiseOwner())
        <flux:card class="max-w-xl">
            <flux:heading size="lg">{{ __('Your plan') }}</flux:heading>
            @if ($this->subscription)
                <flux:text class="mt-2">
                    {{ $this->subscription->plan->name }} · {{ $this->subscription->plan->priceLabel() }}
                    @if ($this->subscription->ends_at)
                        · {{ __('renews on :date', ['date' => $this->subscription->ends_at->format('d M Y')]) }}
                    @endif
                </flux:text>
            @else
                <flux:text class="mt-2">{{ __('You have no active plan. Paid modules are locked.') }}</flux:text>
            @endif
            <flux:button class="mt-4" size="sm" :href="route('subscription.show')" wire:navigate>{{ __('View plans') }}</flux:button>
        </flux:card>
    @endif
</div>
