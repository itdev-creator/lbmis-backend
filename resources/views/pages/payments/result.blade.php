<?php

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Services\Gkash\GkashException;
use App\Services\Payments\PaymentReconciler;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Payment')] class extends Component {
    public Payment $payment;

    public int $checks = 0;

    public function mount(string $cartId): void
    {
        /** @var User $user */
        $user = Auth::user();

        $payment = Payment::query()->with('plan')->where('cart_id', $cartId)->first();

        // Only the paying company's users, or staff who may see all payments.
        abort_if($payment === null, 404);
        abort_unless($user->can('payments.view') || ($user->franchisee_id !== null && $payment->franchisee_id === $user->franchisee_id), 404);

        $this->payment = $payment;
    }

    /**
     * While pending, look again every few seconds; after a minute, ask GKash directly.
     */
    public function refreshStatus(): void
    {
        $this->payment->refresh();
        $this->checks++;

        if ($this->payment->status === PaymentStatus::Pending && $this->checks % 12 === 0) {
            try {
                $this->payment = app(PaymentReconciler::class)->check($this->payment);
            } catch (GkashException) {
                // try again on the next round
            }
        }
    }
}; ?>

<div class="flex w-full max-w-xl flex-col gap-6" @if ($payment->status === \App\Enums\PaymentStatus::Pending && $checks < 60) wire:poll.5s="refreshStatus" @endif>
    <flux:heading size="xl" level="1">{{ __('Payment') }}</flux:heading>

    <flux:card>
        @switch ($payment->status)
            @case (\App\Enums\PaymentStatus::Paid)
                <flux:callout icon="check-circle" variant="success" :heading="__('Payment successful')">
                    <flux:callout.text>
                        @if ($payment->plan)
                            {{ __(':plan is now active.', ['plan' => $payment->plan->name]) }}
                        @endif
                    </flux:callout.text>
                </flux:callout>
                @break
            @case (\App\Enums\PaymentStatus::Failed)
                <flux:callout icon="x-circle" variant="danger" :heading="__('Payment not completed')">
                    <flux:callout.text>{{ $payment->description ?: __('No money was taken. You can try again from My subscription.') }}</flux:callout.text>
                </flux:callout>
                @break
            @case (\App\Enums\PaymentStatus::Refunded)
                <flux:callout icon="arrow-uturn-left" :heading="__('Payment refunded')" />
                @break
            @default
                <flux:callout icon="clock" variant="warning" :heading="__('Waiting for the result from GKash')">
                    <flux:callout.text>{{ __('This page updates by itself. You can also close it: we record the result as soon as GKash sends it.') }}</flux:callout.text>
                </flux:callout>
        @endswitch

        <dl class="mt-5 grid grid-cols-3 gap-y-2 text-sm">
            <dt class="text-zinc-500">{{ __('Amount') }}</dt>
            <dd class="col-span-2">{{ $payment->amountLabel() }}</dd>
            <dt class="text-zinc-500">{{ __('For') }}</dt>
            <dd class="col-span-2">{{ $payment->purpose->label() }}{{ $payment->plan ? ' · '.$payment->plan->name : '' }}</dd>
            <dt class="text-zinc-500">{{ __('Reference') }}</dt>
            <dd class="col-span-2">{{ $payment->cart_id }}</dd>
            @if ($payment->po_id)
                <dt class="text-zinc-500">{{ __('GKash reference') }}</dt>
                <dd class="col-span-2">{{ $payment->po_id }}</dd>
            @endif
            @if ($payment->payment_type)
                <dt class="text-zinc-500">{{ __('Paid with') }}</dt>
                <dd class="col-span-2">{{ $payment->payment_type }}</dd>
            @endif
        </dl>
    </flux:card>

    @can('subscription.view-own')
        <div>
            <flux:button :href="route('subscription.show')" wire:navigate>{{ __('Back to My subscription') }}</flux:button>
        </div>
    @endcan
</div>
