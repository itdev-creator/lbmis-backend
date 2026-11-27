<?php

namespace Tests\Unit;

use App\Services\Gkash\GkashClient;
use PHPUnit\Framework\TestCase;

/**
 * Uses the worked examples from GKash's documentation (doc.gkash.my, Web Payments).
 */
class GkashSignatureTest extends TestCase
{
    private GkashClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new GkashClient('https://api-staging.pay.asia', 'M161-U-999', 'SIGNATUREKEY9999');
    }

    public function test_amounts_are_signed_without_the_decimal_point(): void
    {
        $this->assertSame('10000', GkashClient::signatureAmount('100.00'));
        $this->assertSame('100', GkashClient::signatureAmount('1'));
        $this->assertSame('010', GkashClient::signatureAmount('0.1'));
    }

    public function test_checkout_request_signature_matches_gkash_example(): void
    {
        $this->assertSame(
            '52d08ae99588d9ac00dbd59ae1ec6d3bf44657feeebaa4758517aa79aa6e791315a82055c5e498c780e2f977575c755d5623cd5ad198d780cde2742a7b92041c',
            $this->client->requestSignature('merchant-reference-712893', '100.00'),
        );
    }

    public function test_refund_request_signature_matches_gkash_example(): void
    {
        $this->assertSame(
            '8e7265cda1a7d756bd155dba27cb91cda579a3b2ac54ea1edf0bf2ecbf9b0a4b929a9667f9bce2aa54b43a761313d6b68561a9d1ae5207274704aa6e45d6e89f',
            $this->client->requestSignature('merchant-reference-712894', '100.00'),
        );
    }

    public function test_callback_signature_matches_gkash_example(): void
    {
        $callback = $this->exampleCallback();

        $this->assertSame($callback['signature'], $this->client->resultSignature(
            'M161-PO-999999', 'merchant-reference-712893', '100.00', 'MYR', '88 - Transferred',
        ));
        $this->assertTrue($this->client->verifyResult($callback));
    }

    public function test_tampered_or_foreign_results_are_rejected(): void
    {
        $this->assertFalse($this->client->verifyResult(['amount' => '1.00'] + $this->exampleCallback()));
        $this->assertFalse($this->client->verifyResult(['status' => '66 - Failed'] + $this->exampleCallback()));
        $this->assertFalse($this->client->verifyResult(['CID' => 'M999-U-1'] + $this->exampleCallback()));
        $this->assertFalse($this->client->verifyResult(['signature' => ''] + $this->exampleCallback()));
    }

    public function test_status_codes(): void
    {
        $this->assertTrue(GkashClient::isSuccess('88 - Transferred'));
        $this->assertTrue(GkashClient::isFailure('66 - Failed'));
        $this->assertFalse(GkashClient::isSuccess('11 - Pending'));
        $this->assertFalse(GkashClient::isFailure('11 - Pending'));
    }

    /**
     * @return array<string, string>
     */
    private function exampleCallback(): array
    {
        return [
            'CID' => 'M161-U-999',
            'POID' => 'M161-PO-999999',
            'status' => '88 - Transferred',
            'description' => '00 - Approved',
            'cartid' => 'merchant-reference-712893',
            'currency' => 'MYR',
            'amount' => '100.00',
            'signature' => '7a93e3420fef64dc7ac439cde5bcb8f6daaeff4336e1cc533dc70da64056a369a88ed3550d7b344a2feba0666413a676ec00fb4a93647f9dd207fe1e56e83fce',
            'PaymentType' => 'Master Credit',
        ];
    }
}
