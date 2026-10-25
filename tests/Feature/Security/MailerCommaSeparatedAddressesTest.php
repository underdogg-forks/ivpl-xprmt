<?php

namespace Tests\Feature\Security;

use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;

/**
 * Mailer CC/BCC comma-separated email addresses support.
 *
 * The mailer form accepts CC and BCC fields as text inputs (not type="email").
 * Users may provide multiple addresses separated by commas, as per the previous
 * version (1.7.1 and earlier). These tests verify comma-separated addresses work.
 */
class MailerCommaSeparatedAddressesTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'email_send_method', 'setting_value' => 'phpmail']);
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'smtp_mail_from', 'setting_value' => 'noreply@company.internal']);
        $this->actingAsAdmin();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_accepts_comma_separated_cc_addresses(): void
    {
        $clientId  = $this->databaseInsert('ip_clients', ['client_name' => 'Test Client', 'client_email' => 'client@test.local']);
        $invoiceId = $this->seedInvoice($clientId);

        $response = $this->postWithValidCsrfToken("/mailer/send_invoice/{$invoiceId}", [
            'subject'      => 'Invoice',
            'body'         => 'Pay invoice',
            'cc'           => 'alice@company.internal, bob@company.internal',
            'bcc'          => '',
            'pdf_template' => '',
        ]);

        $this->assertResponseHasNoPhpErrors($response);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_accepts_comma_separated_bcc_addresses(): void
    {
        $clientId  = $this->databaseInsert('ip_clients', ['client_name' => 'Test Client', 'client_email' => 'client@test.local']);
        $invoiceId = $this->seedInvoice($clientId);

        $response = $this->postWithValidCsrfToken("/mailer/send_invoice/{$invoiceId}", [
            'subject'      => 'Invoice',
            'body'         => 'Pay invoice',
            'cc'           => '',
            'bcc'          => 'audit@company.internal, archive@company.internal',
            'pdf_template' => '',
        ]);

        $this->assertResponseHasNoPhpErrors($response);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_accepts_spaces_around_comma_separated_addresses(): void
    {
        $clientId  = $this->databaseInsert('ip_clients', ['client_name' => 'Test Client', 'client_email' => 'client@test.local']);
        $invoiceId = $this->seedInvoice($clientId);

        $response = $this->postWithValidCsrfToken("/mailer/send_invoice/{$invoiceId}", [
            'subject'      => 'Invoice',
            'body'         => 'Pay invoice',
            'cc'           => 'alice@company.internal  ,  bob@company.internal  ,  charlie@company.internal',
            'bcc'          => '',
            'pdf_template' => '',
        ]);

        $this->assertResponseHasNoPhpErrors($response);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_accepts_trailing_comma_in_addresses(): void
    {
        $clientId  = $this->databaseInsert('ip_clients', ['client_name' => 'Test Client', 'client_email' => 'client@test.local']);
        $invoiceId = $this->seedInvoice($clientId);

        $response = $this->postWithValidCsrfToken("/mailer/send_invoice/{$invoiceId}", [
            'subject'      => 'Invoice',
            'body'         => 'Pay invoice',
            'cc'           => 'alice@company.internal, bob@company.internal,',
            'bcc'          => '',
            'pdf_template' => '',
        ]);

        $this->assertResponseHasNoPhpErrors($response);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_accepts_comma_separated_addresses_in_quotes(): void
    {
        $clientId = $this->databaseInsert('ip_clients', ['client_name' => 'Test Client', 'client_email' => 'client@test.local']);
        $quoteId  = $this->seedQuote($clientId);

        $response = $this->postWithValidCsrfToken("/mailer/send_quote/{$quoteId}", [
            'subject'      => 'Quote',
            'body'         => 'Please approve',
            'cc'           => 'alice@company.internal, bob@company.internal',
            'bcc'          => '',
            'pdf_template' => '',
        ]);

        $this->assertResponseHasNoPhpErrors($response);
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
