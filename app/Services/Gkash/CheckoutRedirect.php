<?php

namespace App\Services\Gkash;

/**
 * Where to send the customer after a GKash checkout: either a URL to redirect to,
 * or an auto-submitting HTML form to show in their browser.
 */
final class CheckoutRedirect
{
    private function __construct(
        public readonly ?string $url,
        public readonly ?string $html,
        public readonly string $cartId,
        public readonly string $poId,
    ) {}

    public static function url(string $url, string $cartId, string $poId): self
    {
        return new self($url, null, $cartId, $poId);
    }

    public static function html(string $html, string $cartId, string $poId): self
    {
        return new self(null, $html, $cartId, $poId);
    }
}
