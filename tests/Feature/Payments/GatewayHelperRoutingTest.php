<?php

namespace Tests\Feature\Payments;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * GHSA-2849-wpq6-pq9g (#1837, #1838): Payment_information::stripe() and paypal() are internal helpers that form()
 * calls after it has checked the invoice, its balance and the enabled gateways. They were public, so CodeIgniter routed
 * /guest/payment_information/stripe/<anything> to them and they rendered the gateway view (publishable key, client id,
 * currency, flags) without any of those checks, even with online payments disabled.
 *
 * Every refusal below is paired with the same helper reached legitimately through form(), so a 404 cannot be an
 * artefact of the setup.
 */
#[Group('security')]
#[Group('payments')]
final class GatewayHelperRoutingTest extends AbstractTestCase
{
    private const PUBLISHABLE_KEY = 'pk_test_routing_probe_123';

    private const PAYPAL_CLIENT_ID = 'paypal-client-id-routing-probe';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsGuest();
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'gateway_stripe_apiKeyPublic', 'setting_value' => self::PUBLISHABLE_KEY]);
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'gateway_paypal_clientId', 'setting_value' => self::PAYPAL_CLIENT_ID]);
    }

    #[Test]
    public function it_does_not_route_a_direct_request_to_the_stripe_helper(): void
    {
        /* Arrange: Stripe is configured but online payments are NOT enabled, and the key names no invoice at all */

        /* Act */
        $anyKey  = $this->get('/guest/payment_information/stripe/does-not-exist');
        $realKey = $this->get('/guest/payment_information/stripe/' . $this->seedPayableInvoiceKey());

        /* Assert */
        $this->assertResponseStatusCode($anyKey, 404);
        $this->assertResponseStatusCode($realKey, 404);
        $this->assertResponseBodyNotContains($anyKey, self::PUBLISHABLE_KEY);
        $this->assertResponseBodyNotContains($realKey, self::PUBLISHABLE_KEY);
    }

    #[Test]
    public function it_does_not_route_a_direct_request_to_the_paypal_helper(): void
    {
        /* Arrange */

        /* Act */
        $anyKey  = $this->get('/guest/payment_information/paypal/does-not-exist');
        $realKey = $this->get('/guest/payment_information/paypal/' . $this->seedPayableInvoiceKey());

        /* Assert */
        $this->assertResponseStatusCode($anyKey, 404);
        $this->assertResponseStatusCode($realKey, 404);
        $this->assertResponseBodyNotContains($anyKey, self::PAYPAL_CLIENT_ID);
        $this->assertResponseBodyNotContains($realKey, self::PAYPAL_CLIENT_ID);
    }

    #[Test]
    public function it_still_reaches_the_stripe_helper_through_the_checked_form(): void
    {
        /* Arrange */
        $this->enableGateway('stripe');
        $urlKey = $this->seedPayableInvoiceKey();

        /* Act */
        $response = $this->get('/guest/payment_information/form/' . $urlKey);

        /* Assert: the same helper that answers 404 when called directly renders when form() calls it */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyContains($response, self::PUBLISHABLE_KEY);
        $this->assertResponseBodyContains($response, 'create_checkout_session');
    }

    #[Test]
    public function it_still_reaches_the_paypal_helper_through_the_checked_form(): void
    {
        /* Arrange */
        $this->enableGateway('paypal');
        $urlKey = $this->seedPayableInvoiceKey();

        /* Act */
        $response = $this->get('/guest/payment_information/form/' . $urlKey);

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyContains($response, self::PAYPAL_CLIENT_ID);
    }

    #[Test]
    public function it_writes_the_invoice_key_into_the_stripe_script_as_a_json_string(): void
    {
        /* Arrange */
        $this->enableGateway('stripe');
        $urlKey = $this->seedPayableInvoiceKey();

        /* Act */
        $response = $this->get('/guest/payment_information/form/' . $urlKey);

        /* Assert: json_encode with the hex flags escapes the slashes, a raw single-quoted echo would not */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyContains($response, 'create_checkout_session\/' . $urlKey);
        $this->assertResponseBodyNotContains($response, "create_checkout_session/{$urlKey}'");
    }

    private function enableGateway(string $driver): void
    {
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => "gateway_{$driver}_enabled", 'setting_value' => '1']);
    }

    private function seedPayableInvoiceKey(): string
    {
        $urlKey = 'gw-' . bin2hex(random_bytes(6));
        $this->seedInvoice($this->seedClient(), [
            'invoice_url_key'   => $urlKey,
            'invoice_status_id' => 2,
            'payment_method'    => 0,
        ], ['invoice_balance' => '100.00', 'invoice_total' => '100.00']);

        return $urlKey;
    }
}
