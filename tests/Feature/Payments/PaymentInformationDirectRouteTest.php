<?php

namespace Tests\Feature\Payments;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * GHSA-2849-wpq6-pq9g: Payment_information::form() validates the invoice, the balance and the enabled gateways and then
 * renders the gateway view through $this->{$payment_provider}(). CodeIgniter routes every public controller method, so
 * while stripe() and paypal() were public a guest could request /guest/payment_information/stripe/<anything> and
 * /paypal/<anything> directly and get the gateway view with the configured publishable key / client id even with online
 * payments and the gateway disabled. Same bug class as GHSA-x2m4-962x-cphw.
 */
#[Group('security')]
#[Group('payments')]
final class PaymentInformationDirectRouteTest extends AbstractTestCase
{
    private const STRIPE_KEY = 'pk_test_PUBLISHABLE_KEY_1234';

    private const PAYPAL_ID = 'PAYPAL_CLIENT_ID_5678';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsGuest();
        $this->setSetting('gateway_stripe_apiKeyPublic', self::STRIPE_KEY);
        $this->setSetting('gateway_paypal_clientId', self::PAYPAL_ID);
    }

    #[Test]
    public function it_does_not_route_stripe_directly_even_with_online_payments_and_the_gateway_disabled(): void
    {
        /* Arrange */
        $this->setSetting('enable_online_payments', '0');
        $this->setSetting('gateway_stripe_enabled', '0');

        /* Act: no valid invoice key at all */
        $response = $this->get('/guest/payment_information/stripe/not-a-real-key');

        /* Assert */
        self::assertSame(404, $response->statusCode());
        $this->assertResponseBodyNotContains($response, self::STRIPE_KEY);
    }

    #[Test]
    public function it_does_not_route_paypal_directly_even_with_online_payments_and_the_gateway_disabled(): void
    {
        /* Arrange */
        $this->setSetting('enable_online_payments', '0');
        $this->setSetting('gateway_paypal_enabled', '0');

        /* Act */
        $response = $this->get('/guest/payment_information/paypal/not-a-real-key');

        /* Assert */
        self::assertSame(404, $response->statusCode());
        $this->assertResponseBodyNotContains($response, self::PAYPAL_ID);
    }

    #[Test]
    public function it_still_renders_the_enabled_gateways_through_form_for_a_payable_invoice(): void
    {
        /* Arrange: positive control, the helpers stay reachable from form() */
        $this->setSetting('enable_online_payments', '1');
        $this->setSetting('gateway_stripe_enabled', '1');
        $this->setSetting('gateway_paypal_enabled', '1');
        $urlKey = bin2hex(random_bytes(16));
        $this->seedInvoice($this->seedClient(), ['invoice_url_key' => $urlKey, 'invoice_status_id' => 2], ['invoice_balance' => '50.00']);

        /* Act */
        $stripe = $this->get('/guest/payment_information/form/' . $urlKey . '/stripe');
        $paypal = $this->get('/guest/payment_information/form/' . $urlKey . '/paypal');

        /* Assert */
        self::assertSame(200, $stripe->statusCode());
        $this->assertResponseBodyContains($stripe, self::STRIPE_KEY);
        self::assertSame(200, $paypal->statusCode());
        $this->assertResponseBodyContains($paypal, self::PAYPAL_ID);
    }

    private function setSetting(string $key, string $value): void
    {
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => $key, 'setting_value' => '']);
        $this->databaseUpdate('ip_settings', ['setting_value' => $value], ['setting_key' => $key]);
    }
}
