<?php

namespace Tests\Feature\Payments;

use Cryptor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * Payment gateway hardening that is only visible at the boundary to the provider: what the guest controllers do
 * before they call Stripe or PayPal, and what they do when a call fails.
 *
 * The fakes record every outbound request in a capture file (STRIPE_MOCK_REQUEST_CAPTURE and
 * PAYPAL_MOCK_REQUEST_CAPTURE). An empty file means the provider was never contacted, and each test pairs that with a
 * positive control where the same capture file does fill, so an empty file cannot be an artefact of the setup.
 * These replace three tests that asserted `true` with a comment saying the behaviour was tested elsewhere.
 */
#[Group('security')]
#[Group('payments')]
final class PaymentGatewaySecurityTest extends AbstractTestCase
{
    private const ENCRYPTION_KEY = '0123456789abcdef0123456789abcdef';

    private const ERROR_MESSAGE = 'Payment system error detected! If this message persists, please contact us.';

    /** @var list<string> */
    private array $captureFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        require_once ROOT_PATH . '/application/libraries/Cryptor.php';
        $this->databaseInsertOrIgnore('ip_settings', [
            'setting_key'   => 'gateway_stripe_apiKey',
            'setting_value' => Cryptor::Encrypt('sk_test_fake_key', self::ENCRYPTION_KEY),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->captureFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_validates_input_before_api_calls(): void
    {
        /* Arrange: ids that CodeIgniter's URI filter lets through but that are not a Checkout Session id ("cs_" plus 8 to 250 id characters) */
        $capture = $this->newCaptureFile();
        $this->withEnvironment(['STRIPE_MOCK_RESPONSES' => json_encode([]), 'STRIPE_MOCK_REQUEST_CAPTURE' => $capture]);
        $malformed = [
            'one character short of the minimum' => 'cs_1234567',
            'upper case prefix'                  => 'CS_test12345678',
            'wrong object prefix'                => 'pi_test12345678',
            'dot inside the id'                  => 'cs_test.12345678',
            'one character over the maximum'     => 'cs_' . str_repeat('a', 251),
        ];
        $merchantBefore = $this->databaseCount('ip_merchant_responses');
        $paymentsBefore = $this->databaseCount('ip_payments');

        foreach ($malformed as $label => $id) {
            /* Act */
            $response = $this->get('/guest/gateways/stripe/callback/' . $id);

            /* Assert: refused locally with a clean redirect and the generic message, nothing booked */
            $this->assertResponseRedirectsToRoute($response, 'guest/view/invoices');
            self::assertSame(self::ERROR_MESSAGE, $response->sessionValue('alert_error'), $label);
        }
        self::assertSame('', (string) file_get_contents($capture), 'No malformed checkout session id may reach the Stripe SDK.');
        self::assertSame($merchantBefore, $this->databaseCount('ip_merchant_responses'));
        self::assertSame($paymentsBefore, $this->databaseCount('ip_payments'));

        /* Positive control: a well-formed id goes through to Stripe, which proves the capture file records calls */
        $this->withEnvironment(['STRIPE_MOCK_RESPONSES' => json_encode([$this->stripeMissingSession()])]);
        $this->get('/guest/gateways/stripe/callback/cs_test_wellformed1');
        self::assertStringContainsString('cs_test_wellformed1', (string) file_get_contents($capture));
    }

    #[Test]
    public function it_initializes_variables_before_exception_handling(): void
    {
        /* Arrange: three ways the Stripe call can fail, none of them a "session not found" answer */
        $failures = [
            'Stripe answers 500'                        => [['status' => 500, 'body' => json_encode(['error' => ['type' => 'api_error', 'message' => 'boom']])]],
            'Stripe answers something that is not JSON' => [['status' => 200, 'body' => '<html>bad gateway</html>']],
            'the transport itself throws'               => [],
        ];
        $merchantBefore = $this->databaseCount('ip_merchant_responses');
        $paymentsBefore = $this->databaseCount('ip_payments');
        $logFile        = APPPATH . 'logs/log-' . date('Y-m-d') . '.php';
        $logOffset      = is_file($logFile) ? filesize($logFile) : 0;

        foreach ($failures as $label => $queue) {
            $this->withEnvironment(['STRIPE_MOCK_RESPONSES' => json_encode($queue)]);

            /* Act */
            $response = $this->get('/guest/gateways/stripe/callback/cs_test_failurecase1');

            /* Assert: the finally block ran on unassigned state without a fatal error, so the visitor is redirected with the error message */
            self::assertTrue($response->isRedirect(), "{$label}: expected a redirect, got status {$response->statusCode()}.");
            $this->assertResponseRedirectsToRoute($response, 'guest/view/invoices');
            self::assertSame(self::ERROR_MESSAGE, $response->sessionValue('alert_error'), $label);
            self::assertSame($merchantBefore, $this->databaseCount('ip_merchant_responses'), "{$label}: no log row without a resolved invoice");
            self::assertSame($paymentsBefore, $this->databaseCount('ip_payments'), $label);
        }

        /* Assert: PHP did not have to warn about reading a variable the failed call never assigned */
        clearstatcache(true, $logFile);
        $logged = is_file($logFile) ? (string) file_get_contents($logFile, false, null, $logOffset) : '';
        self::assertStringNotContainsString('Undefined variable', $logged, 'The callback must initialise its variables before the try block.');
        self::assertStringNotContainsString('Severity: Warning', $logged);
    }

    #[Test]
    public function it_delays_oauth_calls_until_needed(): void
    {
        /* Arrange: every request below is refused by a local guard, so PayPal must not be contacted, not even to authorize */
        $capture = $this->newCaptureFile();
        $this->withEnvironment(['PAYPAL_MOCK_RESPONSES' => json_encode([]), 'PAYPAL_MOCK_REQUEST_CAPTURE' => $capture]);
        $draft = $this->databaseFetchOne('ip_invoices', ['invoice_id' => $this->seedPayable(['invoice_status_id' => 1])])['invoice_url_key'];
        $paid  = $this->databaseFetchOne('ip_invoices', ['invoice_id' => $this->seedPayable([], ['invoice_balance' => '0.00'])])['invoice_url_key'];

        /* Act */
        $unknown = $this->post('/guest/gateways/paypal/paypal_create_order/does-not-exist');
        $notYet  = $this->post('/guest/gateways/paypal/paypal_create_order/' . $draft);
        $already = $this->post('/guest/gateways/paypal/paypal_create_order/' . $paid);

        /* Assert */
        $this->assertResponseStatusCode($unknown, 404);
        $this->assertResponseStatusCode($notYet, 404);
        self::assertTrue($already->isRedirect(), 'An already paid invoice is sent back to its page.');
        self::assertSame('', (string) file_get_contents($capture), 'A request that fails a local guard must not authorize against PayPal.');

        /* Positive control: a payable invoice does call PayPal (the authorization is made when the order is created) */
        $payable = $this->databaseFetchOne('ip_invoices', ['invoice_id' => $this->seedPayable()])['invoice_url_key'];
        $this->withEnvironment(['PAYPAL_MOCK_RESPONSES' => json_encode([
            ['status' => 200, 'body' => json_encode(['access_token' => 'fake-bearer-token'])],
            ['status' => 201, 'body' => json_encode(['id' => 'PAYPAL-ORDER-LAZY', 'status' => 'CREATED'])],
        ])]);
        $created = $this->post('/guest/gateways/paypal/paypal_create_order/' . $payable);
        self::assertSame('PAYPAL-ORDER-LAZY', json_decode($created->body(), true)['id'] ?? null);
        self::assertNotSame('', (string) file_get_contents($capture), 'The capture file must record the call that was needed.');
    }

    private function newCaptureFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'gateway-capture-');
        self::assertNotFalse($file);
        $this->captureFiles[] = $file;

        return $file;
    }

    private function stripeMissingSession(): array
    {
        return [
            'status' => 404,
            'body'   => json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such checkout.session']]),
        ];
    }

    private function seedPayable(array $overrides = [], array $amountOverrides = []): int
    {
        return $this->seedInvoice(
            $this->seedClient(),
            array_merge(['invoice_status_id' => 2], $overrides),
            array_merge(['invoice_balance' => '50.00'], $amountOverrides)
        );
    }
}
