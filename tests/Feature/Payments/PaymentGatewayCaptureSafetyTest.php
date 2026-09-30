<?php

namespace Tests\Feature\Payments;

use Cryptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * PR #1749.
 *
 * GHSA-m2c5-pmxf-qfh5: Paypal::paypal_capture_payment() captured the funds at PayPal first and only
 * afterwards checked the invoice, so an invoice that changed between order creation and capture left
 * the customer charged with no payment recorded; a hidden invoice ended in an uncaught exception
 * (HTTP 500) and the merchant log always said "successful".
 *
 * GHSA-r382-4chp-xj3p: the Stripe callback crashed on any invalid session id, and the PayPal library
 * made a live OAuth request on every instantiation, i.e. for every request that reached the controller.
 *
 * "Not captured" is proven two ways: the mock queue holds no capture response (an attempt would
 * exhaust it), and the last request the fake HTTP client saw is inspected.
 */
#[Group('security')]
class PaymentGatewayCaptureSafetyTest extends AbstractTestCase
{
    /** @var list<string> */
    private array $captureFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // StripeClient validates its API key on construction, so without a (syntactically valid, encrypted-at-rest)
        // key every Stripe request would fail before reaching the code under test and the tests could not tell.
        require_once ROOT_PATH . '/application/libraries/Cryptor.php';
        $this->databaseInsertOrIgnore('ip_settings', [
            'setting_key'   => 'gateway_stripe_apiKey',
            'setting_value' => Cryptor::Encrypt('sk_test_fake_key', (string) env('ENCRYPTION_KEY')),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->captureFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // PayPal: nothing is captured unless the order still matches the invoice
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, string, string}>
     */
    public static function ordersThatMustNotBeCaptured(): array
    {
        return [
            'balance was raised after the order'  => [[], ['invoice_balance' => '80.00'], '50.00', 'EUR'],
            'balance was lowered after the order' => [[], ['invoice_balance' => '30.00'], '50.00', 'EUR'],
            'invoice paid through another route'  => [[], ['invoice_balance' => '0.00'], '50.00', 'EUR'],
            'invoice no longer public (draft)'    => [['invoice_status_id' => 1], [], '50.00', 'EUR'],
            'invoice cancelled'                   => [['invoice_status_id' => 5], [], '50.00', 'EUR'],
            'order in a different currency'       => [[], [], '50.00', 'USD'],
            'order amount off by one cent'        => [[], [], '50.01', 'EUR'],
            'order amount zero'                   => [[], [], '0.00', 'EUR'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedOrderIds(): array
    {
        return [
            'path traversal' => ['..%2F..%2Fv1%2Fidentity'],
            'with a space'   => ['ORDER%20ONE'],
            'query string'   => ['ORDER%3Fx%3D1'],
            'html'           => ['%3Cscript%3E'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedSessionIds(): array
    {
        return [
            'not a checkout session' => ['pi_123456789'],
            'too short'              => ['cs_1'],
            'empty prefix only'      => ['cs_'],
            'illegal characters'     => ['cs_test_%3Cscript%3E'],
            'path traversal'         => ['cs_..%2F..%2Fx1234'],
            'far too long'           => ['cs_' . str_repeat('a', 300)],
        ];
    }

    /**
     * @param array<string, mixed> $invoiceOverrides
     * @param array<string, mixed> $amountOverrides
     */
    #[Test]
    #[DataProvider('ordersThatMustNotBeCaptured')]
    public function it_never_captures_an_order_that_no_longer_matches_the_invoice(array $invoiceOverrides, array $amountOverrides, string $orderAmount, string $orderCurrency): void
    {
        /* Arrange */
        $this->configurePaypal();
        $invoiceId = $this->seedPayableInvoice($invoiceOverrides, $amountOverrides);
        $file      = $this->captureFile();

        $this->mockPaypal([$this->authResponse(), $this->orderResponse($invoiceId, $orderAmount, $orderCurrency)], $file);

        /* Act */
        $response = $this->post('/guest/gateways/paypal/paypal_capture_payment/ORDER-STALE');

        /* Assert: the customer was not charged... */
        self::assertLessThan(500, $response->statusCode(), 'a stale order must be refused gracefully, never with a server error');
        self::assertStringContainsString('/v2/checkout/orders/ORDER-STALE', (string) $this->lastRequest($file)['url']);
        self::assertStringNotContainsString('/capture', (string) $this->lastRequest($file)['url'], 'captureOrder() must not have been called');
        self::assertSame('GET', $this->lastRequest($file)['method']);

        /* ...and nothing was recorded, so there is nothing to reconcile. */
        $this->assertDatabaseMissing('ip_payments', ['invoice_id' => $invoiceId]);
        $this->assertDatabaseMissing('ip_merchant_responses', ['invoice_id' => $invoiceId]);
    }

    #[Test]
    public function it_captures_and_records_when_the_order_still_matches_the_invoice(): void
    {
        /* Arrange */
        $this->configurePaypal();
        $invoiceId = $this->seedPayableInvoice();
        $file      = $this->captureFile();

        $this->mockPaypal([
            $this->authResponse(),
            $this->orderResponse($invoiceId, '50.00'),
            $this->captureResponse($invoiceId, '50.00', 'CAP-OK'),
        ], $file);

        /* Act */
        $this->post('/guest/gateways/paypal/paypal_capture_payment/ORDER-OK');

        /* Assert */
        $last = $this->lastRequest($file);
        self::assertSame('POST', $last['method']);
        self::assertStringEndsWith('/v2/checkout/orders/ORDER-OK/capture', (string) $last['url']);
        $this->assertDatabaseHas('ip_payments', ['invoice_id' => $invoiceId, 'payment_external_id' => 'CAP-OK', 'payment_amount' => '50.00']);
        $this->assertDatabaseHas('ip_merchant_responses', ['invoice_id' => $invoiceId, 'merchant_response_successful' => 1]);
    }

    #[Test]
    public function it_refuses_without_capturing_when_the_order_cannot_be_read_from_paypal(): void
    {
        /* Arrange */
        $this->configurePaypal();
        $invoiceId = $this->seedPayableInvoice();
        $file      = $this->captureFile();

        $this->mockPaypal([$this->authResponse(), ['status' => 404, 'body' => json_encode(['name' => 'RESOURCE_NOT_FOUND'])]], $file);

        /* Act */
        $response = $this->post('/guest/gateways/paypal/paypal_capture_payment/ORDER-GONE');

        /* Assert */
        self::assertLessThan(500, $response->statusCode());
        self::assertSame('GET', $this->lastRequest($file)['method']);
        $this->assertDatabaseMissing('ip_payments', ['invoice_id' => $invoiceId]);
    }

    #[Test]
    public function it_refuses_without_capturing_when_the_order_names_no_invoice(): void
    {
        /* Arrange */
        $this->configurePaypal();
        $this->seedPayableInvoice();
        $file = $this->captureFile();

        $this->mockPaypal([$this->authResponse(), ['status' => 200, 'body' => json_encode(['id' => 'O', 'purchase_units' => [['amount' => ['value' => '50.00', 'currency_code' => 'EUR']]]])]], $file);

        /* Act */
        $response = $this->post('/guest/gateways/paypal/paypal_capture_payment/ORDER-NOINV');

        /* Assert */
        self::assertLessThan(500, $response->statusCode());
        self::assertSame('GET', $this->lastRequest($file)['method']);
        $this->assertDatabaseCount('ip_payments', 0, []);
    }

    #[Test]
    public function it_flags_a_capture_that_paypal_accepted_but_could_not_be_recorded_instead_of_logging_success(): void
    {
        /* Arrange: the order matched at check time, but the capture that actually came back is for a
         * different amount (the invoice changed in the gap) — the post-capture guard refuses to record it. */
        $this->configurePaypal();
        $invoiceId = $this->seedPayableInvoice();
        $file      = $this->captureFile();

        $this->mockPaypal([
            $this->authResponse(),
            $this->orderResponse($invoiceId, '50.00'),
            $this->captureResponse($invoiceId, '10.00', 'CAP-GAP'),
        ], $file);

        /* Act */
        $response = $this->post('/guest/gateways/paypal/paypal_capture_payment/ORDER-GAP');

        /* Assert: the money moved (the capture request was sent) but no payment was recorded... */
        self::assertLessThan(500, $response->statusCode());
        self::assertSame('POST', $this->lastRequest($file)['method']);
        $this->assertDatabaseMissing('ip_payments', ['payment_external_id' => 'CAP-GAP']);

        /* ...and the merchant log tells the truth instead of "successful". */
        $row = $this->databaseFetchOne('ip_merchant_responses', ['invoice_id' => $invoiceId]);
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['merchant_response_successful']);
        self::assertStringContainsString('NOT recorded locally', (string) $row['merchant_response']);
    }

    // -------------------------------------------------------------------------
    // PayPal: constructing the controller must not cost an OAuth call
    // -------------------------------------------------------------------------

    #[Test]
    public function it_makes_no_paypal_request_at_all_for_a_request_that_needs_none(): void
    {
        /* Arrange: an EMPTY response queue — any outbound call (including OAuth) would exhaust it and fail. */
        $file = $this->captureFile();
        $this->mockPaypal([], $file);

        /* Act: a GET to the capture endpoint is rejected before PayPal is ever involved. */
        $response = $this->get('/guest/gateways/paypal/paypal_capture_payment/ORDER-X');

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        self::assertSame('', (string) @file_get_contents($file), 'no request may have reached PayPal');
    }

    #[Test]
    #[DataProvider('malformedOrderIds')]
    public function it_rejects_a_malformed_order_id_without_any_paypal_request(string $orderId): void
    {
        /* Arrange */
        $this->configurePaypal();
        $file = $this->captureFile();
        $this->mockPaypal([], $file);

        /* Act */
        $response = $this->post('/guest/gateways/paypal/paypal_capture_payment/' . $orderId);

        /* Assert */
        self::assertLessThan(500, $response->statusCode());
        self::assertSame('', (string) @file_get_contents($file), 'a malformed id must not reach PayPal (no OAuth, no order lookup)');
    }

    // -------------------------------------------------------------------------
    // Stripe: an invalid session id must not crash the callback or cost an API call
    // -------------------------------------------------------------------------

    #[Test]
    #[DataProvider('malformedSessionIds')]
    public function the_stripe_callback_rejects_a_malformed_session_id_before_calling_stripe(string $sessionId): void
    {
        /* Arrange */
        $file = $this->captureFile();
        $this->withEnvironment(['STRIPE_MOCK_RESPONSES' => json_encode([]), 'STRIPE_MOCK_REQUEST_CAPTURE' => $file]);
        $merchantRowsBefore = $this->databaseCount('ip_merchant_responses');

        /* Act */
        $response = $this->get('/guest/gateways/stripe/callback/' . $sessionId);

        /* Assert: redirected, no Stripe call, no row (invoice_id is NOT NULL, so a row would fail). */
        self::assertTrue($response->isRedirect(), 'expected a redirect, got ' . $response->statusCode());
        self::assertSame('', (string) @file_get_contents($file), 'a malformed id must not cost a Stripe API call');
        $this->assertSame($merchantRowsBefore, $this->databaseCount('ip_merchant_responses'));
    }

    #[Test]
    public function the_stripe_callback_survives_a_well_formed_but_unknown_session_without_a_server_error(): void
    {
        /* Arrange: Stripe answers 404 for an id it does not know. */
        $merchantRowsBefore = $this->databaseCount('ip_merchant_responses');
        $this->withEnvironment(['STRIPE_MOCK_RESPONSES' => json_encode([
            ['status' => 404, 'body' => json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'No such checkout.session']])],
        ])]);

        /* Act */
        $response = $this->get('/guest/gateways/stripe/callback/cs_test_doesnotexist12345');

        /* Assert: the intended redirect happens (no HTTP 500 from dereferencing unset state) and no NULL-invoice row is inserted. */
        self::assertTrue($response->isRedirect(), 'expected a redirect, got ' . $response->statusCode());
        self::assertSame($merchantRowsBefore, $this->databaseCount('ip_merchant_responses'));
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    private function configurePaypal(): void
    {
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'gateway_paypal_currency', 'setting_value' => 'EUR']);
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'gateway_paypal_payment_method', 'setting_value' => '1']);
    }

    /**
     * @param array<string, mixed> $invoiceOverrides
     * @param array<string, mixed> $amountOverrides
     */
    private function seedPayableInvoice(array $invoiceOverrides = [], array $amountOverrides = []): int
    {
        return $this->seedInvoice(
            $this->seedClient(),
            array_merge(['invoice_status_id' => 2], $invoiceOverrides),
            array_merge(['invoice_balance' => '50.00', 'invoice_total' => '50.00'], $amountOverrides)
        );
    }

    /**
     * @param list<array<string, mixed>> $responses
     */
    private function mockPaypal(array $responses, string $captureFile): void
    {
        $this->withEnvironment(['PAYPAL_MOCK_RESPONSES' => json_encode($responses), 'PAYPAL_MOCK_REQUEST_CAPTURE' => $captureFile]);
    }

    private function captureFile(): string
    {
        $file = tempnam(ROOT_PATH . '/storage/temp', 'gateway-capture-');
        self::assertNotFalse($file);
        $this->captureFiles[] = $file;

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastRequest(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($decoded, 'the fake HTTP client saw no request');

        return $decoded;
    }

    /**
     * @return array{status: int, body: string}
     */
    private function authResponse(): array
    {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'fake-bearer-token'])];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function orderResponse(int $invoiceId, string $amount, string $currency = 'EUR'): array
    {
        return ['status' => 200, 'body' => json_encode([
            'id'             => 'ORDER-DETAILS',
            'status'         => 'APPROVED',
            'purchase_units' => [['invoice_id' => (string) $invoiceId, 'amount' => ['value' => $amount, 'currency_code' => $currency]]],
        ])];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function captureResponse(int $invoiceId, string $amount, string $captureId): array
    {
        return ['status' => 200, 'body' => json_encode([
            'id'             => 'PAYPAL-ORDER-RESOURCE',
            'purchase_units' => [['payments' => ['captures' => [[
                'status'     => 'COMPLETED',
                'invoice_id' => (string) $invoiceId,
                'id'         => $captureId,
                'amount'     => ['value' => $amount, 'currency_code' => 'EUR'],
            ]]]]],
        ])];
    }
}
