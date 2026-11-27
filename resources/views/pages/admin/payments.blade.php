<?php

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Gkash\GkashException;
use App\Services\Payments\PaymentReconciler;
use App\Services\Payments\PaymentRefunder;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Payments')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public ?int $refundingId = null;
    public string $refundAmount = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Payment>
     */
    #[Computed]
    public function payments(): LengthAwarePaginator
    {
        return Payment::query()
            ->with(['franchisee', 'plan'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('cart_id', "%{$this->search}%")
                ->orWhereLike('po_id', "%{$this->search}%")
                ->orWhereHas('franchisee', fn ($f) => $f->whereLike('company_name', "%{$this->search}%"))))
            ->when(PaymentStatus::tryFrom($this->status) !== null, fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(25);
    }

    public function checkStatus(int $id): void
    {
        Gate::authorize('payments.view');
        $payment = Payment::query()->findOrFail($id);

        try {
            $payment = app(PaymentReconciler::class)->check($payment);
            Flux::toast(text: __(':ref: :status (:gateway)', [
                'ref' => $payment->cart_id, 'status' => $payment->status->label(), 'gateway' => $payment->gateway_status ?? __('no result yet'),
            ]));
        } catch (GkashException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        unset($this->payments);
    }

    public function startRefund(int $id): void
    {
        Gate::authorize('payments.refund');
        $payment = Payment::query()->findOrFail($id);

        $this->resetValidation();
        $this->refundingId = $payment->id;
        $left = (float) $payment->amount - (float) ($payment->refunded_amount ?? 0);
        $this->refundAmount = number_format($left, 2, '.', '');

        Flux::modal('refund-form')->show();
    }

    public function refund(): void
    {
        Gate::authorize('payments.refund');
        $this->validate(['refundAmount' => ['required', 'numeric', 'min:0.01']]);

        $payment = Payment::query()->findOrFail((int) $this->refundingId);

        try {
            app(PaymentRefunder::class)->refund($payment, number_format((float) $this->refundAmount, 2, '.', ''));
            Flux::modal('refund-form')->close();
            Flux::toast(variant: 'success', text: __('Refund accepted by GKash.'));
        } catch (GkashException $e) {
            $this->addError('refundAmount', $e->getMessage());
        }

        unset($this->payments);
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Payments') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Every payment made through GKash. Pending payments are checked with GKash every 15 minutes.') }}</flux:text>
    </div>

    <div class="grid gap-3 md:grid-cols-3">
        <div class="md:col-span-2">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search reference or franchisee')" />
        </div>
        <flux:select wire:model.live="status">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (\App\Enums\PaymentStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->payments">
        <flux:table.columns>
            <flux:table.column>{{ __('Date') }}</flux:table.column>
            <flux:table.column>{{ __('Franchisee') }}</flux:table.column>
            <flux:table.column>{{ __('For') }}</flux:table.column>
            <flux:table.column>{{ __('Amount') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->payments as $payment)
                <flux:table.row :key="$payment->id">
                    <flux:table.cell>
                        {{ $payment->created_at?->format('d M Y H:i') }}
                        <flux:text size="sm">{{ $payment->cart_id }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>{{ $payment->franchisee?->company_name }}</flux:table.cell>
                    <flux:table.cell>{{ $payment->purpose->label() }}{{ $payment->plan ? ' · '.$payment->plan->name : '' }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $payment->amountLabel() }}
                        @if ($payment->refunded_amount)
                            <flux:text size="sm">{{ __('refunded RM :amount', ['amount' => number_format((float) $payment->refunded_amount, 2)]) }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$payment->status->color()">{{ $payment->status->label() }}</flux:badge>
                        <flux:text size="sm">{{ $payment->gateway_status }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell class="text-end">
                        @if ($payment->status === \App\Enums\PaymentStatus::Pending)
                            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="checkStatus({{ $payment->id }})">{{ __('Check') }}</flux:button>
                        @endif
                        @can('payments.refund')
                            @if ($payment->status === \App\Enums\PaymentStatus::Paid)
                                <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="startRefund({{ $payment->id }})">{{ __('Refund') }}</flux:button>
                            @endif
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6"><flux:text>{{ __('No payments yet.') }}</flux:text></flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @can('payments.refund')
        <flux:modal name="refund-form" class="w-full md:w-96">
            <form wire:submit="refund" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Refund payment') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('The money goes back to the card or account that paid. This cannot be undone. The plan stays active: change it on the Franchisees page if needed.') }}</flux:text>
                </div>
                <flux:input wire:model="refundAmount" :label="__('Amount to refund (RM)')" inputmode="decimal" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger">{{ __('Refund') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
