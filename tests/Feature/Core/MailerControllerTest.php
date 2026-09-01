<?php

namespace Tests\Feature\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * Mailer controller — application/modules/mailer/controllers/Mailer.php.
 *
 * Renders the "send this invoice/quote" form and dispatches the mail. Absorbs
 * Issue1497SmtpSenderTest (the from-address defaulting regression).
 */
#[Group('mailer')]
class MailerControllerTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'email_send_method', 'setting_value' => 'phpmail']);
    }

    // -------------------------------------------------------------------------
    // Read — the send-invoice form
    // -------------------------------------------------------------------------

    #[Test]
    public function it_renders_the_send_invoice_form_for_a_seeded_invoice(): void
    {
        /* Arrange */
        $clientId  = $this->seedClient(['client_name' => 'Mailer Form Client']);
        $invoiceId = $this->seedInvoice($clientId, ['invoice_number' => 'INV-MAIL-0001']);

        /* Act */
        $response = $this->get('/mailer/invoice/' . $invoiceId);

        /* Assert */
        $this->assertResponseBodyContains($response, 'INV-MAIL-0001');
        $this->assertResponseBodyNotContains($response, 'A PHP Error was encountered');
    }

    // -------------------------------------------------------------------------
    // From-address defaulting — #1497 regression
    // -------------------------------------------------------------------------

    #[Test]
    public function it_prefills_the_from_address_with_the_smtp_mail_from_setting(): void
    {
        /* Arrange */
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'smtp_mail_from', 'setting_value' => 'noreply@company.example']);
        $this->databaseUpdate('ip_settings', ['setting_value' => 'noreply@company.example'], ['setting_key' => 'smtp_mail_from']);
        $invoiceId = $this->seedInvoice($this->seedClient());

        /* Act */
        $response = $this->get('/mailer/invoice/' . $invoiceId);

        /* Assert */
        $this->assertResponseBodyContains($response, 'value="noreply@company.example"');
        $this->assertResponseBodyNotContains($response, 'value="admin@test.local"');
    }

    #[Test]
    public function it_falls_back_to_the_current_user_email_when_smtp_mail_from_is_empty(): void
    {
        /* Arrange */
        $this->databaseDelete('ip_settings', ['setting_key' => 'smtp_mail_from']);
        $invoiceId = $this->seedInvoice($this->seedClient());

        /* Act */
        $response = $this->get('/mailer/invoice/' . $invoiceId);

        /* Assert */
        $this->assertResponseBodyContains($response, 'value="admin@test.local"');
        $this->assertResponseBodyNotContains($response, 'value="noreply@company.example"');
    }

    // -------------------------------------------------------------------------
    // Guest access — always last
    // -------------------------------------------------------------------------

    #[Test]
    public function it_redirects_a_guest_away_from_the_mailer(): void
    {
        /* Arrange */
        $invoiceId = $this->seedInvoice($this->seedClient(), ['invoice_number' => 'INV-MAIL-SECRET']);
        $this->actingAsGuest();

        /* Act */
        $response = $this->get('/mailer/invoice/' . $invoiceId);

        /* Assert */
        self::assertTrue($response->isRedirect(), 'Unauthenticated request must redirect to login.');
        $this->assertResponseBodyNotContains($response, 'INV-MAIL-SECRET');
    }
}
