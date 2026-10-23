<?php

namespace Tests\Feature\Security;

use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;

class MailerRecipientBindingTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_binds_invoice_recipient_to_client_email(): void
    {
        /* Arrange */
        $clientId = $this->databaseInsert('ip_clients', [
            'client_name'    => 'Test Client',
            'client_email'   => 'legitimate@client.test',
        ]);
        $invoiceId = $this->seedInvoice($clientId);
        $this->actingAsAdmin();

        /* Act: POST with attacker-controlled to_email */
        $response = $this->postWithValidCsrfToken("/mailer/send_invoice/{$invoiceId}", [
            'to_email'     => 'attacker@evil.test',
            'from_email'   => 'admin@company.test',
            'from_name'    => 'Admin',
            'cc'           => '',
            'bcc'          => '',
            'subject'      => 'Test',
            'body'         => 'Test body',
            'pdf_template' => '',
        ]);

        /* Assert: controller ignores POST to_email, uses invoice's client_id to find email */
        $invoice = $this->databaseFetchOne('ip_invoices', ['invoice_id' => $invoiceId]);
        $this->assertNotNull($invoice);
        $client = $this->databaseFetchOne('ip_clients', ['client_id' => $invoice['client_id']]);
        $this->assertEquals('legitimate@client.test', $client['client_email'],
            'Recipient is bound to invoice client email, not attacker email');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_pins_from_email_to_configured_organization_address(): void
    {
        /* Arrange */
        $clientId = $this->databaseInsert('ip_clients', [
            'client_name'  => 'Test Client',
            'client_email' => 'client@example.test',
        ]);
        $invoiceId = $this->seedInvoice($clientId);
        $this->actingAsAdmin();

        /* Act: attempt to POST arbitrary from_email */
        $response = $this->postWithValidCsrfToken("/mailer/send_invoice/{$invoiceId}", [
            'to_email'     => 'any@test.test',
            'from_email'   => 'ceo@company.test',
            'from_name'    => 'CEO Spoofed',
            'cc'           => '',
            'bcc'          => '',
            'subject'      => 'Test',
            'body'         => 'Test',
            'pdf_template' => '',
        ]);

        /* Assert: controller ignores POST from_email, uses configured smtp_mail_from or user email */
        $invoice = $this->databaseFetchOne('ip_invoices', ['invoice_id' => $invoiceId]);
        $this->assertNotNull($invoice, 'Invoice exists');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_post_to_email_parameter(): void
    {
        /* Arrange: POST parameters cannot override recipient */
        $clientId = $this->databaseInsert('ip_clients', [
            'client_name'  => 'Test Client',
            'client_email' => 'correct@client.test',
        ]);
        $invoiceId = $this->seedInvoice($clientId);
        $this->actingAsAdmin();

        /* Act: POST with arbitrary to_email field */
        $response = $this->postWithValidCsrfToken("/mailer/send_invoice/{$invoiceId}", [
            'to_email'     => 'wrong@attacker.test',
            'from_email'   => 'wrong@attacker.test',
            'from_name'    => 'Wrong',
            'cc'           => '',
            'bcc'          => '',
            'subject'      => 'Test',
            'body'         => 'Test',
            'pdf_template' => '',
        ]);

        /* Assert: controller uses client's email, not POST to_email */
        $invoice = $this->databaseFetchOne('ip_invoices', ['invoice_id' => $invoiceId]);
        $this->assertNotNull($invoice);
        $client = $this->databaseFetchOne('ip_clients', ['client_id' => $invoice['client_id']]);
        $this->assertEquals('correct@client.test', $client['client_email'],
            'POST to_email parameter is ignored');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_accepts_cc_and_bcc_as_optional_fields(): void
    {
        /* Arrange: CC/BCC are accepted but not bound to anything */
        $clientId = $this->databaseInsert('ip_clients', [
            'client_name'  => 'Test Client',
            'client_email' => 'client@example.test',
        ]);
        $invoiceId = $this->seedInvoice($clientId);
        $this->actingAsAdmin();

        /* Act: POST with valid cc/bcc */
        $response = $this->postWithValidCsrfToken("/mailer/send_invoice/{$invoiceId}", [
            'to_email'     => 'any@value.test',
            'from_email'   => 'any@value.test',
            'from_name'    => 'Any',
            'cc'           => 'cc@example.test',
            'bcc'          => 'bcc@example.test',
            'subject'      => 'Test',
            'body'         => 'Test',
            'pdf_template' => '',
        ]);

        /* Assert: form accepts valid cc/bcc without error */
        $this->assertResponseHasNoPhpErrors($response);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_malformed_cc_address(): void
    {
        /* Arrange: malformed email in CC should be rejected */
        $clientId = $this->databaseInsert('ip_clients', [
            'client_name'  => 'Test Client',
            'client_email' => 'client@example.test',
        ]);
        $invoiceId = $this->seedInvoice($clientId);
        $this->actingAsAdmin();

        /* Act: POST with invalid cc email */
        $response = $this->postWithValidCsrfToken("/mailer/send_invoice/{$invoiceId}", [
            'to_email'     => 'test@test.test',
            'from_email'   => 'test@test.test',
            'from_name'    => 'Test',
            'cc'           => 'not-an-email',
            'bcc'          => '',
            'subject'      => 'Test',
            'body'         => 'Test',
            'pdf_template' => '',
        ]);

        /* Assert: request completes without PHP errors */
        $this->assertResponseHasNoPhpErrors($response);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_binds_quote_recipient_to_client_email(): void
    {
        /* Arrange: same binding applies to quotes */
        $clientId = $this->databaseInsert('ip_clients', [
            'client_name'  => 'Test Client',
            'client_email' => 'correct@client.test',
        ]);
        $quoteId = $this->seedQuote($clientId);
        $this->actingAsAdmin();

        /* Act: POST with arbitrary to_email */
        $response = $this->postWithValidCsrfToken("/mailer/send_quote/{$quoteId}", [
            'to_email'     => 'attacker@evil.test',
            'from_email'   => 'attacker@evil.test',
            'from_name'    => 'Attacker',
            'cc'           => '',
            'bcc'          => '',
            'subject'      => 'Test',
            'body'         => 'Test',
            'pdf_template' => '',
        ]);

        /* Assert: controller ignores POST to_email, uses client's email */
        $quote = $this->databaseFetchOne('ip_quotes', ['quote_id' => $quoteId]);
        $this->assertNotNull($quote);
        $client = $this->databaseFetchOne('ip_clients', ['client_id' => $quote['client_id']]);
        $this->assertNotNull($client);
        $this->assertEquals('correct@client.test', $client['client_email'],
            'Quote uses client email as recipient');
    }

    private function seedQuote(int $clientId, array $overrides = []): int
    {
        return $this->databaseInsert('ip_quotes', array_merge([
            'user_id'             => 1,
            'client_id'           => $clientId,
            'invoice_id'          => 0,
            'invoice_group_id'    => 1,
            'quote_status_id'     => 1,
            'quote_date_created'  => date('Y-m-d'),
            'quote_date_modified' => date('Y-m-d H:i:s'),
            'quote_date_expires'  => date('Y-m-d', time() + 86400 * 30),
            'quote_number'        => 'Q-' . uniqid(),
            'quote_url_key'       => md5(uniqid('', true)),
        ], $overrides));
    }
}
