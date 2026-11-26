<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Franchisee;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Gkash\GkashClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GkashPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const CID = 'M161-U-999';

    private GkashClient $gkash;

    private Franchisee $franchisee;

    private User $owner;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('lbmis:sync-permissions')->assertSuccessful();

        config(['services.gkash' => [
            'base_url' => 'https://api-staging.pay.asia',
            'cid' => self::CID,
            'signature_key' => 'SIGNATUREKEY9999',
            'timeout' => 5,
        ]]);
        Http::preventStrayRequests();

        $this->gkash = GkashClient::fromConfig();
        $this->franchisee = Franchisee::factory()->create();
        $this->owner = User::factory()->franchiseOwner($this->franchisee)->create(['name' => 'Aminah Binti Ali']);
        $this->plan = Plan::factory()->withModules(['documents', 'tickets'])->create(['price' => 99, 'billing_period' => 'monthly']);
    }

    /**
     * A result signed the way GKash signs it.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signedResult(Payment $payment, string $status = '88 - Transferred', array $overrides = []): array
    {
        $result = array_merge([
            'CID' => self::CID,
            'POID' => 'M161-PO-123456',
            'status' => $status,
            'cartid' => $payment->cart_id,
            'currency' => 'MYR',
            'amount' => (string) $payment->amount,
            'description' => '00 - Approved',
            'PaymentType' => 'Visa Credit 0011',
            'epkey' => 'EPKEY-FIRST',
        ], $overrides);

        $result['signature'] = $this->gkash->resultSignature(
            $result['POID'], $result['cartid'], $result['amount'], $result['currency'], $result['status'],
        );

        return $result;
    }

    public function test_owner_is_sent_to_gkash_with_a_signed_recurring_checkout(): void
    {
        Http::fake(['*/api/payment/form' => fn (Request $request) => Http::response([
            'status' => '11 - Pending', 'cid' => self::CID, 'poId' => 'M161-PO-1',
            'cartId' => $request['v_cartid'], 'amount' => '99.00', 'currency' => 'MYR',
            'redirect' => ['url' => 'https://api-staging.pay.asia/api/v2/GUnifiedSelect.aspx?i=abc'],
        ])]);

        Livewire::actingAs($this->owner)->test('pages::subscription')
            ->call('subscribe', $this->plan->id)
            ->assertRedirect('https://api-staging.pay.asia/api/v2/GUnifiedSelect.aspx?i=abc');

        $payment = Payment::query()->sole();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('M161-PO-1', $payment->po_id);

        Http::assertSent(fn (Request $request) => $request['recurringtype'] === 'MONTHLY'
            && $request['v_amount'] === '99.00'
            && $request['v_billemail'] === $this->owner->email
            && $request['callbackurl'] === route('payments.gkash.callback')
            && $request['signature'] === $this->gkash->requestSignature($payment->cart_id, '99.00'));
    }

    public function test_html_checkout_is_shown_through_the_redirect_page(): void
    {
        Http::fake(['*/api/payment/form' => fn (Request $request) => Http::response([
            'status' => '11 - Pending', 'cartId' => $request['v_cartid'],
            'redirect' => ['html' => '<form name="paymentWeb" method="post"></form>'],
        ])]);

        $test = Livewire::actingAs($this->owner)->test('pages::subscription')->call('subscribe', $this->plan->id);
        $cartId = Payment::query()->sole()->cart_id;
        $test->assertRedirect(route('payments.go', ['cartId' => $cartId]));

        $this->actingAs($this->owner)->get(route('payments.go', ['cartId' => $cartId]))
            ->assertOk()->assertSee('name="paymentWeb"', false);

        // The form is handed out once only.
        $this->actingAs($this->owner)->get(route('payments.go', ['cartId' => $cartId]))->assertStatus(410);
    }

    public function test_checkout_error_marks_the_payment_failed(): void
    {
        Http::fake(['*/api/payment/form' => Http::response('Server error', 500)]);

        Livewire::actingAs($this->owner)->test('pages::subscription')
            ->call('subscribe', $this->plan->id)
            ->assertNoRedirect();

        $this->assertSame(PaymentStatus::Failed, Payment::query()->sole()->status);
    }

    public function test_free_plan_starts_without_gkash(): void
    {
        Http::fake();
        $free = Plan::factory()->create(['price' => 0]);

        Livewire::actingAs($this->owner)->test('pages::subscription')->call('subscribe', $free->id);

        Http::assertNothingSent();
        $this->assertSame($free->id, $this->franchisee->activeSubscription()?->plan_id);
    }

    public function test_successful_callback_activates_the_plan_and_saves_the_card(): void
    {
        $payment = Payment::factory()->create(['franchisee_id' => $this->franchisee->id, 'plan_id' => $this->plan->id]);
        $old = Subscription::factory()->create(['franchisee_id' => $this->franchisee->id]);

        $this->post(route('payments.gkash.callback'), $this->signedResult($payment))
            ->assertOk()->assertSeeText('OK');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('M161-PO-123456', $payment->po_id);
        $this->assertArrayNotHasKey('epkey', (array) $payment->gateway_response);

        $subscription = $this->franchisee->activeSubscription();
        $this->assertNotNull($subscription);
        $this->assertSame($this->plan->id, $subscription->plan_id);
        $this->assertSame('EPKEY-FIRST', $subscription->gkash_epkey);
        $this->assertTrue($subscription->auto_renew);
        $this->assertSame(now()->addMonthNoOverflow()->subDay()->toDateString(), $subscription->ends_at?->toDateString());
        $this->assertSame(SubscriptionStatus::Cancelled, $old->fresh()?->status);

        // The card token is encrypted in the database.
        $raw = DB::table('subscriptions')->where('id', $subscription->id)->value('gkash_epkey');
        $this->assertNotSame('EPKEY-FIRST', $raw);

        $this->assertTrue($this->owner->fresh()?->canUseModule('tickets'));
    }

    public function test_repeated_results_are_applied_once(): void
    {
        $payment = Payment::factory()->create(['franchisee_id' => $this->franchisee->id, 'plan_id' => $this->plan->id]);
        $result = $this->signedResult($payment);

        $this->post(route('payments.gkash.callback'), $result)->assertOk();
        $this->post(route('payments.gkash.callback'), $result)->assertOk();
        $this->post(route('payments.gkash.return'), $result)->assertRedirect();

        $this->assertSame(1, Subscription::query()->where('franchisee_id', $this->franchisee->id)->count());
    }

    public function test_callback_with_a_bad_signature_is_rejected(): void
    {
        $payment = Payment::factory()->create(['franchisee_id' => $this->franchisee->id, 'plan_id' => $this->plan->id]);
        $result = ['signature' => str_repeat('a', 128)] + $this->signedResult($payment);

        $this->post(route('payments.gkash.callback'), $result)->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()?->status);
    }

    public function test_callback_for_the_wrong_amount_is_ignored(): void
    {
        $payment = Payment::factory()->create(['franchisee_id' => $this->franchisee->id, 'plan_id' => $this->plan->id]);

        $this->post(route('payments.gkash.callback'), $this->signedResult($payment, overrides: ['amount' => '1.00']))->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()?->status);
        $this->assertNull($this->franchisee->activeSubscription());
    }

    public function test_failed_payment_grants_nothing(): void
    {
        $payment = Payment::factory()->create(['franchisee_id' => $this->franchisee->id, 'plan_id' => $this->plan->id]);

        $this->post(route('payments.gkash.callback'), $this->signedResult($payment, '66 - Failed'))->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()?->status);
        $this->assertNull($this->franchisee->activeSubscription());
    }

    public function test_return_page_does_not_start_a_session(): void
    {
        $payment = Payment::factory()->create(['franchisee_id' => $this->franchisee->id, 'plan_id' => $this->plan->id]);

        $this->post(route('payments.gkash.return'), $this->signedResult($payment))
            ->assertRedirect(route('payments.result', ['cartId' => $payment->cart_id]))
            ->assertCookieMissing((string) config('session.cookie'));

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()?->status);
    }

    public function test_result_page_is_only_for_the_paying_company_and_payment_staff(): void
    {
        $payment = Payment::factory()->paid()->create(['franchisee_id' => $this->franchisee->id, 'plan_id' => $this->plan->id]);
        $url = route('payments.result', ['cartId' => $payment->cart_id]);

        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('Payment successful');
        $this->actingAs(User::factory()->franchiseOwner()->create())->get($url)->assertNotFound();
        $this->actingAs(User::factory()->role('manager')->create())->get($url)->assertOk();
    }

    public function test_due_plan_is_renewed_with_the_saved_card(): void
    {
        $subscription = $this->dueSubscription();

        Http::fake(['*/api/payment/submit' => fn (Request $request) => Http::response($this->signedResult(
            Payment::query()->where('cart_id', $request['v_cartid'])->sole(),
            overrides: ['epkey' => 'EPKEY-SECOND'],
        ))]);

        $this->artisan('lbmis:charge-renewals')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request['epkey'] === 'EPKEY-FIRST' && $request['recurringtype'] === 'MONTHLY');

        $subscription->refresh();
        $this->assertSame('EPKEY-SECOND', $subscription->gkash_epkey);
        $this->assertSame(now()->addDay()->addMonthNoOverflow()->subDay()->toDateString(), $subscription->ends_at?->toDateString());
        $this->assertSame(0, $subscription->renewal_attempts);

        $renewal = Payment::query()->where('purpose', PaymentPurpose::Renewal->value)->sole();
        $this->assertSame(PaymentStatus::Paid, $renewal->status);
        $this->assertSame($subscription->id, $renewal->subscription_id);

        // A second run the same day charges nothing more.
        $this->artisan('lbmis:charge-renewals')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_unsigned_renewal_answer_is_confirmed_with_a_status_query(): void
    {
        $subscription = $this->dueSubscription();

        Http::fake([
            '*/api/payment/submit' => Http::response(['status' => '88 - Transferred', 'signature' => 'not-valid']),
            '*/api/payment/query' => fn (Request $request) => Http::response([
                'CID' => self::CID, 'POID' => 'M161-PO-777', 'cartid' => $request['cartid'],
                'amount' => '99.00', 'currency' => 'MYR', 'status' => '88 - Transferred',
            ]),
        ]);

        $this->artisan('lbmis:charge-renewals')->assertSuccessful();

        $this->assertSame(PaymentStatus::Paid, Payment::query()->where('purpose', 'renewal')->sole()->status);
        $this->assertTrue($subscription->fresh()?->ends_at?->isAfter(now()));
    }

    public function test_plan_expires_after_repeated_renewal_failures(): void
    {
        $subscription = $this->dueSubscription();

        Http::fake(['*/api/payment/submit' => fn (Request $request) => Http::response($this->signedResult(
            Payment::query()->where('cart_id', $request['v_cartid'])->sole(), '66 - Failed',
        ))]);

        foreach (range(1, 3) as $day) {
            $this->travel(1)->days();
            $this->artisan('lbmis:charge-renewals')->assertSuccessful();
        }

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Expired, $subscription->status);
        $this->assertFalse($subscription->auto_renew);
        $this->assertSame(3, Payment::query()->where('purpose', 'renewal')->where('status', 'failed')->count());
    }

    public function test_pending_payment_is_settled_by_the_status_check(): void
    {
        $payment = Payment::factory()->create(['franchisee_id' => $this->franchisee->id, 'plan_id' => $this->plan->id]);
        $payment->forceFill(['created_at' => now()->subMinutes(20)])->save();

        Http::fake(['*/api/payment/query' => Http::response([
            'CID' => self::CID, 'POID' => 'M161-PO-888', 'cartid' => $payment->cart_id,
            'amount' => '99.00', 'currency' => 'MYR', 'status' => '88 - Transferred',
        ])]);

        $this->artisan('lbmis:reconcile-payments')->assertSuccessful();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()?->status);
        $this->assertSame($this->plan->id, $this->franchisee->activeSubscription()?->plan_id);
    }

    public function test_admin_refunds_a_payment_and_managers_cannot(): void
    {
        $payment = Payment::factory()->paid()->create(['franchisee_id' => $this->franchisee->id, 'plan_id' => $this->plan->id]);
        Http::fake(['*/api/payment/refund' => Http::response([
            'CID' => self::CID, 'status' => '00 - Refund Successful', 'cartid' => $payment->cart_id, 'amount' => '99.00',
        ])]);

        Livewire::actingAs(User::factory()->role('manager')->create())->test('pages::admin.payments')
            ->call('startRefund', $payment->id)
            ->assertForbidden();

        Livewire::actingAs(User::factory()->role('admin')->create())->test('pages::admin.payments')
            ->call('startRefund', $payment->id)
            ->call('refund')
            ->assertHasNoErrors();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame('99.00', (string) $payment->refunded_amount);
        Http::assertSent(fn (Request $request) => $request['version'] === '1.3.1'
            && $request['signature'] === $this->gkash->requestSignature($payment->cart_id, '99.00'));
    }

    private function dueSubscription(): Subscription
    {
        return Subscription::factory()->create([
            'franchisee_id' => $this->franchisee->id,
            'plan_id' => $this->plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->toDateString(),
            'source' => 'gkash',
            'gkash_epkey' => 'EPKEY-FIRST',
            'auto_renew' => true,
        ]);
    }
}
