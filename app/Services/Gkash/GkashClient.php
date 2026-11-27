<?php

namespace App\Services\Gkash;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * GKash online payments API (https://doc.gkash.my).
 *
 * All requests are signed with SHA512 over "key;CID;cartid;amount;currency" (upper-cased),
 * and GKash's results are signed over "key;CID;POID;cartid;amount;currency;status".
 * The amount is written with 2 decimals and the point removed: 100.00 -> 10000.
 */
class GkashClient
{
    public const CURRENCY = 'MYR';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $cid,
        private readonly string $signatureKey,
        private readonly int $timeout = 30,
    ) {}

    public static function fromConfig(): self
    {
        $cid = config('services.gkash.cid');
        $key = config('services.gkash.signature_key');

        if (! is_string($cid) || $cid === '' || ! is_string($key) || $key === '') {
            throw new GkashException('GKash is not configured: set GKASH_CID and GKASH_SIGNATURE_KEY.');
        }

        return new self(
            (string) config('services.gkash.base_url', 'https://api-staging.pay.asia'),
            $cid,
            $key,
            (int) config('services.gkash.timeout', 30),
        );
    }

    public function cid(): string
    {
        return $this->cid;
    }

    /**
     * "100" -> "10000", "0.1" -> "010" (2 decimals, point removed), as GKash signs amounts.
     */
    public static function signatureAmount(string|float $amount): string
    {
        return str_replace('.', '', number_format((float) $amount, 2, '.', ''));
    }

    public static function formatAmount(string|float $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Signature for checkout, recurring charge, query and refund requests.
     */
    public function requestSignature(string $cartId, string|float $amount, string $currency = self::CURRENCY): string
    {
        return self::sha512([$this->signatureKey, $this->cid, $cartId, self::signatureAmount($amount), $currency]);
    }

    /**
     * Signature GKash puts on payment results (callback, return, recurring response).
     */
    public function resultSignature(string $poId, string $cartId, string|float $amount, string $currency, string $status): string
    {
        return self::sha512([$this->signatureKey, $this->cid, $poId, $cartId, self::signatureAmount($amount), $currency, $status]);
    }

    /**
     * True when a payment result really comes from GKash for our merchant ID.
     *
     * @param  array<string, mixed>  $result  callback / return / recurring response fields
     */
    public function verifyResult(array $result): bool
    {
        $given = strtolower((string) ($result['signature'] ?? ''));
        $cid = (string) ($result['CID'] ?? $result['cid'] ?? '');

        if ($given === '' || strcasecmp($cid, $this->cid) !== 0) {
            return false;
        }

        $expected = $this->resultSignature(
            (string) ($result['POID'] ?? $result['poId'] ?? ''),
            (string) ($result['cartid'] ?? $result['cartId'] ?? ''),
            (string) ($result['amount'] ?? '0'),
            (string) ($result['currency'] ?? self::CURRENCY),
            (string) ($result['status'] ?? ''),
        );

        return hash_equals($expected, $given);
    }

    /**
     * Step 1 of web payments: create a checkout and get where to send the customer.
     *
     * @param  array<string, string>  $extra  optional fields: v_firstname, v_billemail, v_productdesc, recurringtype ...
     */
    public function createCheckout(string $cartId, string|float $amount, string $returnUrl, string $callbackUrl, array $extra = []): CheckoutRedirect
    {
        $payload = array_merge(array_filter($extra, fn ($value) => $value !== ''), [
            'version' => '1.5.5',
            'CID' => $this->cid,
            'v_currency' => self::CURRENCY,
            'v_amount' => self::formatAmount($amount),
            'v_cartid' => $cartId,
            'signature' => $this->requestSignature($cartId, $amount),
            'returnurl' => $returnUrl,
            'callbackurl' => $callbackUrl,
        ]);

        $json = $this->send('checkout', fn () => $this->http()->asJson()->post('/api/payment/form', $payload));

        if (strcasecmp((string) ($json['cartId'] ?? ''), $cartId) !== 0) {
            throw new GkashException('GKash checkout answered for a different cart ID.');
        }

        $redirect = is_array($json['redirect'] ?? null) ? $json['redirect'] : [];

        if (is_string($redirect['url'] ?? null) && $redirect['url'] !== '') {
            return CheckoutRedirect::url($redirect['url'], $cartId, (string) ($json['poId'] ?? ''));
        }

        if (is_string($redirect['html'] ?? null) && $redirect['html'] !== '') {
            return CheckoutRedirect::html($redirect['html'], $cartId, (string) ($json['poId'] ?? ''));
        }

        throw new GkashException('GKash checkout returned no redirect: '.(string) ($json['status'] ?? 'unknown status'));
    }

    /**
     * Charge a saved card (recurring payment). GKash answers synchronously and returns a new epkey.
     *
     * @return array<string, mixed>
     */
    public function chargeSavedCard(string $cartId, string|float $amount, string $epKey, string $recurringType, string $description = ''): array
    {
        $payload = array_filter([
            'version' => '1.5.5',
            'CID' => $this->cid,
            'v_currency' => self::CURRENCY,
            'v_amount' => self::formatAmount($amount),
            'v_cartid' => $cartId,
            'signature' => $this->requestSignature($cartId, $amount),
            'recurringtype' => $recurringType,
            'epkey' => $epKey,
            'v_productdesc' => $description,
        ], fn ($value) => $value !== '');

        return $this->send('recurring charge', fn () => $this->http()->asForm()->post('/api/payment/submit', $payload));
    }

    /**
     * Real-time status of a payment. The answer is not signed; it comes straight from GKash over HTTPS.
     *
     * @return array<string, mixed>
     */
    public function queryStatus(string $cartId, string|float $amount): array
    {
        return $this->send('status query', fn () => $this->http()->asJson()->post('/api/payment/query', [
            'version' => '1.3.0',
            'CID' => $this->cid,
            'cartid' => $cartId,
            'currency' => self::CURRENCY,
            'amount' => self::formatAmount($amount),
            'signature' => $this->requestSignature($cartId, $amount),
        ]));
    }

    /**
     * Refund a paid transaction (the amount may be less than the original for a partial refund).
     *
     * @return array<string, mixed>
     */
    public function refund(string $cartId, string|float $amount): array
    {
        return $this->send('refund', fn () => $this->http()->asJson()->post('/api/payment/refund', [
            'version' => '1.3.1',
            'CID' => $this->cid,
            'cartid' => $cartId,
            'currency' => self::CURRENCY,
            'amount' => self::formatAmount($amount),
            'signature' => $this->requestSignature($cartId, $amount),
        ]));
    }

    public static function isSuccess(string $status): bool
    {
        return str_starts_with(trim($status), '88');
    }

    public static function isFailure(string $status): bool
    {
        return str_starts_with(trim($status), '66');
    }

    /**
     * @param  list<string>  $parts
     */
    private static function sha512(array $parts): string
    {
        return strtolower(hash('sha512', strtoupper(implode(';', $parts))));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout(10);
    }

    /**
     * Sends a request and returns its JSON body; any failure becomes a GkashException.
     *
     * @param  \Closure(): Response  $request
     * @return array<string, mixed>
     */
    private function send(string $what, \Closure $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new GkashException("Could not reach GKash for {$what}: ".$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new GkashException("GKash {$what} failed with HTTP {$response->status()}.");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new GkashException("GKash {$what} did not return JSON.");
        }

        /** @var array<string, mixed> $json */
        return $json;
    }
}
