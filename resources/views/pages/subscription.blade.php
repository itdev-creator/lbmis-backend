<?php

use App\Enums\PaymentStatus;
use App\Http\Controllers\PaymentRedirectController;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Gkash\GkashException;
use App\Services\Payments\SubscriptionCheckout;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My subscription')] class extends Component {
    private function owner(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    #[Computed]
    public function subscription(): ?Subscription
    {
        return $this->owner()->franchisee?->activeSubscription();
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

    /**
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function payments(): Collection
    {
        $franchiseeId = $this->owner()->franchisee_id;

        return Payment::query()
            ->with('plan')
            ->where('franchisee_id', $franchiseeId ?? 0)
            ->latest()
            ->limit(10)
            ->get();
    }

    /**
     * Start paying for a plan on the GKash payment page (free plans start at once).
     */
    public function subscribe(int $planId): void
    {
        Gate::authorize('subscription.view-own');

        $plan = Plan::query()->where('is_active', true)->findOrFail($planId);
        $current = $this->subscription;

        if ($current !== null && $current->plan_id === $plan->id) {
            Flux::toast(variant: 'warning', text: __('You are already on this plan.'));

            return;
        }

        try {
            $redirect = app(SubscriptionCheckout::class)->start($this->owner(), $plan);
        } catch (GkashException $e) {
            Log::error('GKash checkout failed', ['plan' => $plan->id, 'error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: __('The payment page could not be opened. Please try again in a few minutes.'));

            return;
        }

        if ($redirect === null) {
            unset($this->subscription);
            Flux::toast(variant: 'success', text: __(':plan is now active.', ['plan' => $plan->name]));

            return;
        }

        if ($redirect->url !== null) {
            $this->redirect($redirect->url);

            return;
        }

        Cache::put(PaymentRedirectController::cacheKey($redirect->cartId), (string) $redirect->html, now()->addMinutes(10));
        $this->redirect(route('payments.go', ['cartId' => $redirect->cartId]));
    }

    /**
     * Turn automatic renewal off (the plan stays until its end date) or back on.
     */
    public function toggleAutoRenew(): void
    {
        Gate::authorize('subscription.view-own');
        $subscription = $this->subscription;

        if ($subscription === null) {
            return;
        }

        if (! $subscription->auto_renew && ($subscription->gkash_epkey === null || $subscription->gkash_epkey === '')) {
            Flux::toast(variant: 'warning', text: __('No saved card for this plan. Pay for the plan again to turn on automatic renewal.'));

            return;
        }

        $subscription->update(['auto_renew' => ! $subscription->auto_renew]);
        unset($this->subscription);

        Flux::toast(variant: 'success', text: $subscription->auto_renew
            ? __('Automatic renewal is on.')
            : __('Automatic renewal is off. Your plan stays active until its end date.'));
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
                    · {{ $this->subscription->auto_renew ? __('renews on :date', ['date' => $this->subscription->ends_at->addDay()->format('d M Y')]) : __('ends on :date', ['date' => $this->subscription->ends_at->format('d M Y')]) }}
                @endif
            </flux:text>

            @if (! $this->subscription->plan->isFree())
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <flux:switch :checked="$this->subscription->auto_renew" wire:click="toggleAutoRenew" :label="__('Renew automatically with my saved card')" />
                </div>
                @if ($this->subscription->last_renewal_error)
                    <flux:callout class="mt-3" icon="exclamation-triangle" variant="danger" :heading="__('The last automatic renewal failed')">
                        <flux:callout.text>{{ __('We will try again tomorrow. Reason from the bank: :reason', ['reason' => $this->subscription->last_renewal_error]) }}</flux:callout.text>
                    </flux:callout>
                @endif
            @endif
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
                    @elseif ($plan->isFree())
                        <flux:button class="mt-4" wire:click="subscribe({{ $plan->id }})"
                                     wire:confirm="{{ __('Switch to :plan? Your current plan ends now.', ['plan' => $plan->name]) }}">
                            {{ __('Switch to this plan') }}
                        </flux:button>
                    @else
                        <flux:button class="mt-4" variant="primary" wire:click="subscribe({{ $plan->id }})" wire:loading.attr="disabled"
                                     wire:confirm="{{ __('Pay :price with GKash? Your card is saved and charged automatically each period until you turn renewal off.', ['price' => $plan->priceLabel()]) }}">
                            {{ __('Pay with GKash') }}
                        </flux:button>
                    @endif
                </flux:card>
            @empty
                <flux:text>{{ __('No plans are available yet.') }}</flux:text>
            @endforelse
        </div>
    </div>

    @if ($this->payments->isNotEmpty())
        <div>
            <flux:heading size="lg" class="mb-3">{{ __('Payment history') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('For') }}</flux:table.column>
                    <flux:table.column>{{ __('Amount') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Reference') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->payments as $payment)
                        <flux:table.row :key="$payment->id">
                            <flux:table.cell>{{ $payment->created_at?->format('d M Y H:i') }}</flux:table.cell>
                            <flux:table.cell>{{ $payment->purpose->label() }} · {{ $payment->plan?->name }}</flux:table.cell>
                            <flux:table.cell>{{ $payment->amountLabel() }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$payment->status->color()">{{ $payment->status->label() }}</flux:badge></flux:table.cell>
                            <flux:table.cell>
                                <a href="{{ route('payments.result', ['cartId' => $payment->cart_id]) }}" class="text-sm underline" wire:navigate>{{ $payment->cart_id }}</a>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
