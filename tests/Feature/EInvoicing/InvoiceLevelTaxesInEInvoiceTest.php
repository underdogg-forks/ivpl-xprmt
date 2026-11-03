<?php

namespace Tests\Feature\EInvoicing;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * An invoice-level tax changes the PDF total but is left out of the e-invoice XML (UBL/CII), so the two formats
 * disagreed silently. The invoice view now warns, and the XML route refuses.
 */
#[Group('einvoicing')]
final class InvoiceLevelTaxesInEInvoiceTest extends AbstractTestCase
{
    private const WARNING = 'Invoice-level taxes are not supported in e-invoices';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'einvoicing', 'setting_value' => '1']);
        $this->databaseUpdate('ip_settings', ['setting_value' => '1'], ['setting_key' => 'einvoicing']);
        $this->databaseUpdate('ip_users', ['user_tax_code' => 'TAX-123456'], ['user_id' => 1]);
    }

    #[Test]
    public function it_shows_warning_in_invoice_view_when_taxes_and_einvoicing_present(): void
    {
        /* Arrange */
        $invoiceId = $this->einvoice(withInvoiceTax: true);

        /* Act */
        $response = $this->get("invoices/view/{$invoiceId}");

        /* Assert */
        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString(self::WARNING, $response->body(), 'The invoice view must show the warning about invoice-level taxes.');
    }

    #[Test]
    public function it_does_not_show_warning_when_no_invoice_level_taxes_present(): void
    {
        /* Arrange: positive control, the same e-invoice setup without the tax */
        $invoiceId = $this->einvoice(withInvoiceTax: false);

        /* Act */
        $response = $this->get("invoices/view/{$invoiceId}");

        /* Assert */
        self::assertSame(200, $response->statusCode());
        self::assertStringNotContainsString(self::WARNING, $response->body(), 'The warning must not appear when no invoice-level taxes exist.');
    }

    #[Test]
    public function it_does_not_show_warning_when_einvoicing_inactive(): void
    {
        /* Arrange: the invoice has the tax, but the client does not use e-invoicing */
        $invoiceId = $this->einvoice(withInvoiceTax: true, clientUsesEinvoicing: false);

        /* Act */
        $response = $this->get("invoices/view/{$invoiceId}");

        /* Assert */
        self::assertSame(200, $response->statusCode());
        self::assertStringNotContainsString(self::WARNING, $response->body(), 'The warning must not appear when e-invoicing is not active.');
    }

    #[Test]
    public function it_does_not_show_warning_when_the_issuing_user_cannot_send_e_invoices(): void
    {
        /* Arrange: the client uses e-invoicing, but the user issuing the invoice has no tax code, so no e-invoice can be made anyway */
        $invoiceId = $this->einvoice(withInvoiceTax: true);
        $this->databaseUpdate('ip_users', ['user_tax_code' => ''], ['user_id' => 1]);

        /* Act */
        $response = $this->get("invoices/view/{$invoiceId}");

        /* Assert */
        self::assertSame(200, $response->statusCode());
        self::assertStringNotContainsString(self::WARNING, $response->body());
    }

    #[Test]
    public function it_rejects_xml_generation_when_invoice_level_taxes_present(): void
    {
        /* Arrange */
        $invoiceId = $this->einvoice(withInvoiceTax: true);

        /* Act */
        $response = $this->get("invoices/generate_xml/{$invoiceId}");

        /* Assert */
        self::assertSame(400, $response->statusCode(), 'Should return 400 Bad Request when invoice-level taxes present.');
        self::assertStringContainsString(self::WARNING, $response->body(), 'Error response must explain why XML generation was rejected.');
        self::assertStringContainsString('Please remove all invoice-level taxes from this invoice', $response->body(), 'The response must say what to do.');
    }

    #[Test]
    public function it_does_not_reject_xml_generation_for_an_invoice_without_invoice_level_taxes(): void
    {
        /* Arrange: positive control, the route is reachable for this setup and is only refused because of the tax */
        $invoiceId = $this->einvoice(withInvoiceTax: false);

        /* Act */
        $response = $this->get("invoices/generate_xml/{$invoiceId}");

        /* Assert */
        self::assertNotSame(400, $response->statusCode());
        self::assertStringNotContainsString(self::WARNING, $response->body());
        self::assertNotSame(404, $response->statusCode(), 'The invoice must be eligible for an e-invoice, otherwise the 400 test proves nothing.');
    }

    private function einvoice(bool $withInvoiceTax, bool $clientUsesEinvoicing = true): int
    {
        $clientId  = $this->seedClient($clientUsesEinvoicing ? ['client_einvoicing_active' => 1, 'client_einvoicing_version' => 'Facturxv10'] : []);
        $invoiceId = $this->seedInvoice($clientId);
        $itemId    = $this->databaseInsert('ip_invoice_items', [
            'invoice_id' => $invoiceId, 'item_name' => 'Test Item', 'item_description' => '', 'item_quantity' => '1', 'item_price' => '100.00',
            'item_order' => 1, 'item_date_added' => date('Y-m-d'),
        ]);
        $this->databaseInsert('ip_invoice_item_amounts', [
            'item_id' => $itemId, 'item_subtotal' => '100.00', 'item_tax_total' => '0.00', 'item_discount' => '0.00', 'item_total' => '100.00',
        ]);

        if ($withInvoiceTax) {
            $taxRateId = $this->databaseInsert('ip_tax_rates', ['tax_rate_name' => 'VAT', 'tax_rate_percent' => 19]);
            $this->databaseInsert('ip_invoice_tax_rates', [
                'invoice_id' => $invoiceId, 'tax_rate_id' => $taxRateId, 'invoice_tax_rate_amount' => 19, 'include_item_tax' => 0,
            ]);
        }

        return $invoiceId;
    }
}
