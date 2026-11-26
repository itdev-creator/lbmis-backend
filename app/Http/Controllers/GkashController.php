<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Gkash\GkashClient;
use App\Services\Payments\PaymentProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints GKash calls. Both run without a session or CSRF check (see routes/web.php):
 * GKash's browser redirect is a cross-site POST, so the login cookie is not sent with it.
 */
class GkashController extends Controller
{
    /**
     * Server-to-server result. GKash expects the text "OK".
     */
    public function callback(Request $request, GkashClient $gkash, PaymentProcessor $processor): Response
    {
        $result = $this->fields($request);

        if (! $gkash->verifyResult($result)) {
            Log::warning('GKash callback with an invalid signature', ['cartid' => $result['cartid'] ?? null, 'ip' => $request->ip()]);

            return response('Invalid signature', 400)->header('Content-Type', 'text/plain');
        }

        $payment = Payment::query()->where('cart_id', (string) ($result['cartid'] ?? ''))->first();

        if ($payment === null) {
            Log::warning('GKash callback for an unknown cart ID', ['cartid' => $result['cartid'] ?? null]);
        } else {
            $processor->apply($payment, $result, 'callback');
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * The customer's browser comes back here after paying.
     */
    public function return(Request $request, GkashClient $gkash, PaymentProcessor $processor): RedirectResponse
    {
        $result = $this->fields($request);
        $cartId = (string) ($result['cartid'] ?? '');

        if ($cartId !== '' && $gkash->verifyResult($result)) {
            $payment = Payment::query()->where('cart_id', $cartId)->first();

            if ($payment !== null) {
                $processor->apply($payment, $result, 'return');
            }
        }

        return redirect()->route('payments.result', ['cartId' => $cartId !== '' ? $cartId : 'unknown']);
    }

    /**
     * @return array<string, string>
     */
    private function fields(Request $request): array
    {
        $fields = [];
        foreach ($request->all() as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $fields[$key] = (string) $value;
            }
        }

        return $fields;
    }
}
