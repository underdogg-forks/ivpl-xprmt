<?php

namespace Tests\Feature\Security;

use Cryptor;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * GHSA-82h8-735g-3pfp: "Enable online payments" only hid the pay buttons. The payment form and both gateway
 * controllers never read the setting, so anyone holding a public invoice link could still open the form (and get
 * the gateway's public key) or start a payment while the administrator had online payments switched off.
 *
 * The switch now refuses every route that shows the form or starts or captures a payment. The one route left open
 * is the Stripe return URL: the customer has already paid at Stripe when it is called, so refusing it would keep
 * the money and lose the payment record.
 */
#[Group('security')]
#[Group('payments')]
final class OnlinePaymentsMasterSwitchTest extends AbstractTestCase
{
    private const ENCRYPTION_KEY = '0123456789abcdef0123456789abcdef';

    private const STRIPE_PUBLIC_KEY = 'pk_test_master_switch_marker';

    private const PAYPAL_CLIENT_ID = 'paypal-client-master-switch-marker';

    private string $captureFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        require_once ROOT_PATH . '/application/libraries/Cryptor.php';

        $file = tempnam(sys_get_temp_dir(), 'master-switch-');
        self::assertNotFalse($file);
        $this->captureFile = $file;

        $this->actingAsGuest();
        $this->setSetting('gateway_stripe_enabled', '1');
        $this->setSetting('gateway_paypal_enabled', '1');
        $this->setSetting('gateway_stripe_apiKeyPublic', self::STRIPE_PUBLIC_KEY);
        $this->setSetting('gateway_stripe_apiKey', Cryptor::Encrypt('sk_test_fake_key', self::ENCRYPTION_KEY));
        $this->setSetting('gateway_paypal_clientId', self::PAYPAL_CLIENT_ID);
        $this->setSetting('gateway_stripe_payment_method', '1');
        $this->setSetting('gateway_paypal_payment_method', '1');
        $this->setSetting('gateway_stripe_currency', 'EUR');
        $this->setSetting('gateway_paypal_currency', 'EUR');
    }

    protected function tearDown(): void
    {
        @unlink($this->captureFile);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function switchedOffValues(): array
    {
        return [
            'zero'           => ['0'],
            'empty'          => [''],
            'never saved'    => [null],
            'not a boolean'  => ['yes'],
            'two'            => ['2'],
            'one with space' => ['1 '],
        ];
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function formRoutesWhileSwitchedOff(): array
    {
        $cases = [];

        foreach (self::switchedOffValues() as $label => [$value]) {
            foreach (['no provider' => '', 'stripe' => '/stripe', 'paypal' => '/paypal', 'upper case' => '/STRIPE'] as $route => $suffix) {
                $cases[$label . ', ' . $route] = [$value, $suffix];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string|null, 1: string, 2: string}>
     */
    public static function paypalRoutesWhileSwitchedOff(): array
    {
        $cases = [];

        foreach (self::switchedOffValues() as $label => [$value]) {
            $cases[$label . ', create order (POST)'] = [$value, 'POST', 'paypal_create_order/%s'];
            $cases[$label . ', create order (GET)']  = [$value, 'GET', 'paypal_create_order/%s'];
            $cases[$label . ', capture (POST)']      = [$value, 'POST', 'paypal_capture_payment/ORDER-MASTER-SWITCH'];
            $cases[$label . ', capture (GET)']       = [$value, 'GET', 'paypal_capture_payment/ORDER-MASTER-SWITCH'];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('formRoutesWhileSwitchedOff')]
    public function it_does_not_serve_the_payment_form_while_online_payments_are_switched_off(?string $value, string $suffix): void
    {
        /* Arrange: a payable, public invoice and both gateways enabled; only the master switch is off */
        $this->setMasterSwitch($value);
        [$invoiceId, $urlKey] = $this->seedPayableInvoice();

        /* Act */
        $response = $this->get('/guest/payment_information/form/' . $urlKey . $suffix);

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertResponseBodyNotContains($response, self::STRIPE_PUBLIC_KEY);
        $this->assertResponseBodyNotContains($response, self::PAYPAL_CLIENT_ID);
        $this->assertResponseBodyNotContains($response, 'create_checkout_session');
        $this->assertResponseBodyNotContains($response, 'paypal.com/sdk');
        $this->assertResponseBodyNotContains($response, $urlKey);
        $this->assertDatabaseMissing('ip_payments', ['invoice_id' => $invoiceId]);
    }

    #[Test]
    public function it_serves_the_payment_form_of_the_requested_gateway_while_online_payments_are_switched_on(): void
    {
        /* Arrange */
        $this->setMasterSwitch('1');
        [, $urlKey] = $this->seedPayableInvoice();

        /* Act */
        $stripe = $this->get('/guest/payment_information/form/' . $urlKey . '/stripe');
        $paypal = $this->get('/guest/payment_information/form/' . $urlKey . '/paypal');

        /* Assert: each page carries its own gateway's public value and not the other one's */
        $this->assertResponseStatusCode($stripe, 200);
        $this->assertResponseBodyContains($stripe, self::STRIPE_PUBLIC_KEY);
        $this->assertResponseBodyNotContains($stripe, self::PAYPAL_CLIENT_ID);
        $this->assertResponseStatusCode($paypal, 200);
        $this->assertResponseBodyContains($paypal, self::PAYPAL_CLIENT_ID);
        $this->assertResponseBodyNotContains($paypal, self::STRIPE_PUBLIC_KEY);
    }

    #[Test]
    #[DataProvider('switchedOffValues')]
    public function it_does_not_start_a_stripe_checkout_while_online_payments_are_switched_off(?string $value): void
    {
        /* Arrange: Stripe would answer with a session, so a 404 here is the switch and not a failed API call */
        $this->setMasterSwitch($value);
        [$invoiceId, $urlKey] = $this->seedPayableInvoice();
        $this->withEnvironment(['STRIPE_MOCK_RESPONSES' => json_encode([$this->stripeSessionResponse('unpaid', $urlKey, 'pi_never_created')])]);

        /* Act */
        $response = $this->post('/guest/gateways/stripe/create_checkout_session/' . $urlKey);

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertResponseBodyNotContains($response, 'cs_test_master_switch');
        $this->assertResponseBodyNotContains($response, 'clientSecret');
        $this->assertDatabaseMissing('ip_payments', ['invoice_id' => $invoiceId]);
        $this->assertDatabaseCount('ip_merchant_responses', 0);
    }

    #[Test]
    public function it_starts_a_stripe_checkout_while_online_payments_are_switched_on(): void
    {
        /* Arrange */
        $this->setMasterSwitch('1');
        [, $urlKey] = $this->seedPayableInvoice();
        $this->withEnvironment(['STRIPE_MOCK_RESPONSES' => json_encode([$this->stripeSessionResponse('unpaid', $urlKey, 'pi_started')])]);

        /* Act */
        $response = $this->post('/guest/gateways/stripe/create_checkout_session/' . $urlKey);

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyContains($response, 'clientSecret');
    }

    #[Test]
    #[DataProvider('paypalRoutesWhileSwitchedOff')]
    public function it_does_not_reach_paypal_while_online_payments_are_switched_off(?string $value, string $method, string $route): void
    {
        /* Arrange: PayPal would answer every call, and every call it receives is written to the capture file */
        $this->setMasterSwitch($value);
        [$invoiceId, $urlKey] = $this->seedPayableInvoice();
        $this->withEnvironment([
            'PAYPAL_MOCK_REQUEST_CAPTURE' => $this->captureFile,
            'PAYPAL_MOCK_RESPONSES'       => json_encode([
                ['status' => 200, 'body' => json_encode(['access_token' => 'fake-bearer-token'])],
                ['status' => 201, 'body' => json_encode(['id' => 'ORDER-MASTER-SWITCH', 'status' => 'CREATED'])],
                ['status' => 201, 'body' => json_encode(['id' => 'ORDER-MASTER-SWITCH', 'status' => 'COMPLETED'])],
            ]),
        ]);

        /* Act */
        $response = $this->request($method, '/guest/gateways/paypal/' . sprintf($route, $urlKey));

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
        $this->assertResponseBodyNotContains($response, 'ORDER-MASTER-SWITCH');
        self::assertSame('', (string) file_get_contents($this->captureFile), 'No request may be sent to PayPal.');
        $this->assertDatabaseMissing('ip_payments', ['invoice_id' => $invoiceId]);
        $this->assertDatabaseCount('ip_merchant_responses', 0);
    }

    #[Test]
    public function it_creates_a_paypal_order_while_online_payments_are_switched_on(): void
    {
        /* Arrange */
        $this->setMasterSwitch('1');
        [, $urlKey] = $this->seedPayableInvoice();
        $this->withEnvironment([
            'PAYPAL_MOCK_REQUEST_CAPTURE' => $this->captureFile,
            'PAYPAL_MOCK_RESPONSES'       => json_encode([
                ['status' => 200, 'body' => json_encode(['access_token' => 'fake-bearer-token'])],
                ['status' => 201, 'body' => json_encode(['id' => 'ORDER-MASTER-SWITCH', 'status' => 'CREATED'])],
            ]),
        ]);

        /* Act */
        $response = $this->post('/guest/gateways/paypal/paypal_create_order/' . $urlKey);

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyContains($response, 'ORDER-MASTER-SWITCH');
    }

    #[Test]
    #[DataProvider('switchedOffValues')]
    public function it_still_books_a_stripe_payment_that_was_paid_before_online_payments_were_switched_off(?string $value): void
    {
        /* Arrange: the customer paid at Stripe, then the administrator switched online payments off */
        $this->setMasterSwitch($value);
        [$invoiceId, $urlKey] = $this->seedPayableInvoice();
        $this->withEnvironment(['STRIPE_MOCK_RESPONSES' => json_encode([$this->stripeSessionResponse('paid', $urlKey, 'pi_paid_before_switch_off')])]);

        /* Act */
        $response = $this->get('/guest/gateways/stripe/callback/cs_test_master_switch');

        /* Assert: the money is at Stripe, so the payment must be on the invoice */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseHas('ip_payments', ['invoice_id' => $invoiceId, 'payment_external_id' => 'pi_paid_before_switch_off']);
    }

    #[Test]
    public function it_pins_the_public_routes_of_the_payment_controllers(): void
    {
        /*
         * Every public method of these controllers is a URL. Each one is either refused by the master switch (tested
         * above) or deliberately left open (the Stripe return URL). A new public method must be added to one of the
         * two groups here, and to the tests above when it belongs to the first.
         */

        /* Arrange */
        $expected = [
            'application/modules/guest/controllers/Payment_information.php' => ['form'],
            'application/modules/guest/controllers/gateways/Paypal.php'     => ['paypal_capture_payment', 'paypal_create_order'],
            'application/modules/guest/controllers/gateways/Stripe.php'     => ['callback', 'create_checkout_session'],
        ];

        foreach ($expected as $file => $routes) {
            /* Act */
            $actual = $this->publicRoutesOf(ROOT_PATH . '/' . $file);

            /* Assert */
            self::assertSame($routes, $actual, $file . ' has a public route this test does not know.');
        }
    }

    /**
     * @return list<string>
     */
    private function publicRoutesOf(string $path): array
    {
        $ast   = (new ParserFactory())->createForHostVersion()->parse((string) file_get_contents($path));
        $class = (new NodeFinder())->findFirstInstanceOf((array) $ast, Class_::class);
        self::assertInstanceOf(Class_::class, $class);

        $routes = [];

        foreach ($class->getMethods() as $method) {
            /** @var ClassMethod $method */
            $name = $method->name->toString();

            if ($method->isPublic() && ! str_starts_with($name, '_')) {
                $routes[] = $name;
            }
        }

        sort($routes);

        return $routes;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function seedPayableInvoice(): array
    {
        $invoiceId = $this->seedInvoice($this->seedClient(), ['invoice_status_id' => 2], ['invoice_total' => '50.00', 'invoice_balance' => '50.00']);

        return [$invoiceId, (string) $this->databaseFetchOne('ip_invoices', ['invoice_id' => $invoiceId])['invoice_url_key']];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function stripeSessionResponse(string $paymentStatus, string $urlKey, string $intent): array
    {
        return ['status' => 200, 'body' => json_encode([
            'id'                     => 'cs_test_master_switch',
            'object'                 => 'checkout.session',
            'status'                 => 'complete',
            'mode'                   => 'payment',
            'payment_status'         => $paymentStatus,
            'client_reference_id'    => $urlKey,
            'client_secret'          => 'cs_test_master_switch_secret',
            'payment_intent'         => $intent,
            'currency'               => 'eur',
            'amount_total'           => 5000,
            'amount_received'        => 0,
            'application_fee_amount' => 0,
            'livemode'               => false,
            'cancellation_reason'    => null,
            'last_payment_error'     => null,
        ])];
    }

    private function setMasterSwitch(?string $value): void
    {
        if ($value === null) {
            $this->databaseDelete('ip_settings', ['setting_key' => 'enable_online_payments']);

            return;
        }

        $this->setSetting('enable_online_payments', $value);
    }

    private function setSetting(string $key, string $value): void
    {
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => $key, 'setting_value' => '']);
        $this->databaseUpdate('ip_settings', ['setting_value' => $value], ['setting_key' => $key]);
    }
}
