<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * GHSA-2849-wpq6-pq9g: stripe.php put the invoice key into a single-quoted JavaScript string with a plain echo
 * (paypal.php already used json_encode), so a quote in the key broke out of the string. The user-client modal did the
 * same with the user id taken from the URL. Values fed by the request or by settings are encoded with json_encode and
 * the hex flags; a quote must reach the page encoded, never raw.
 */
#[Group('security')]
final class JavascriptStringContextEscapingTest extends AbstractTestCase
{
    #[Test]
    public function it_encodes_the_invoice_key_in_the_stripe_checkout_script(): void
    {
        /* Arrange */
        $this->actingAsGuest();
        $this->setSetting('enable_online_payments', '1');
        $this->setSetting('gateway_stripe_enabled', '1');
        $urlKey = "ab'cd" . bin2hex(random_bytes(4));
        $this->seedInvoice($this->seedClient(), ['invoice_url_key' => $urlKey, 'invoice_status_id' => 2], ['invoice_balance' => '50.00']);

        /* Act */
        $response = $this->get('/guest/payment_information/form/' . rawurlencode($urlKey) . '/stripe');

        /* Assert: the raw quote never ends a JavaScript string, the encoded one is there */
        self::assertSame(200, $response->statusCode(), 'Body: ' . substr($response->body(), 0, 300));
        $this->assertResponseBodyNotContains($response, "create_checkout_session/ab'cd");
        $this->assertResponseBodyContains($response, 'create_checkout_session\/ab\u0027cd');
    }

    #[Test]
    public function it_encodes_the_user_id_in_the_user_client_modal(): void
    {
        /* Arrange: a user that is still being created keeps its assignments in the session, so the id is not checked */
        $this->actingAsAdmin();
        $this->sessionData['user_clients'] = [1 => 1];

        /* Act */
        $response = $this->ajax('GET', '/users/ajax/modal_add_user_client/ab%27cd');

        /* Assert */
        self::assertSame(200, $response->statusCode(), 'Body: ' . substr($response->body(), 0, 300));
        $this->assertResponseBodyNotContains($response, "user_id: 'ab'cd'");
        $this->assertResponseBodyContains($response, 'user_id: "ab\u0027cd"');
    }

    private function setSetting(string $key, string $value): void
    {
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => $key, 'setting_value' => '']);
        $this->databaseUpdate('ip_settings', ['setting_value' => $value], ['setting_key' => $key]);
    }
}
