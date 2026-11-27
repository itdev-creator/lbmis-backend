<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Shows GKash's auto-submitting payment form when its checkout answers with HTML
 * instead of a URL. The HTML is kept in the cache for a few minutes by the subscription page.
 */
class PaymentRedirectController extends Controller
{
    public function __invoke(Request $request, string $cartId): Response
    {
        /** @var User $user */
        $user = $request->user();

        $payment = Payment::query()->where('cart_id', $cartId)->firstOrFail();
        abort_unless($payment->user_id === $user->id, 404);

        $html = Cache::pull(self::cacheKey($cartId));
        abort_unless(is_string($html) && $html !== '', 410, 'This payment link has expired. Please start again.');

        return response('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Redirecting to GKash</title></head><body>'
            .'<p style="font-family:sans-serif">Taking you to the GKash payment page...</p>'.$html.'</body></html>')
            ->header('Cache-Control', 'no-store');
    }

    public static function cacheKey(string $cartId): string
    {
        return 'gkash-checkout-html:'.$cartId;
    }
}
