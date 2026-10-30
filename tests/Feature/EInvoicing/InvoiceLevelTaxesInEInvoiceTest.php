<?php

namespace Tests\Feature\EInvoicing;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

#[Group('einvoicing')]
final class InvoiceLevelTaxesInEInvoiceTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    #[Test]
    public function it_detects_invoice_level_taxes_on_an_invoice(): void
    {
        /* Arrange */
        $this->loadHelper('e-invoice');
        $clientId = $this->seedClient();
        $invoiceId = $this->seedInvoice($clientId);
        $this->seedInvoiceTax($invoiceId, 19);

        /* Act */
        $has_taxes = invoice_has_invoice_level_taxes($invoiceId);

        /* Assert */
        self::assertTrue($has_taxes, 'invoice_has_invoice_level_taxes() must detect invoice-level taxes.');
    }

    #[Test]
    public function it_returns_false_when_no_invoice_level_taxes_exist(): void
    {
        /* Arrange */
        $this->loadHelper('e-invoice');
        $clientId = $this->seedClient();
        $invoiceId = $this->seedInvoice($clientId);

        /* Act */
        $has_taxes = invoice_has_invoice_level_taxes($invoiceId);

        /* Assert */
        self::assertFalse($has_taxes, 'invoice_has_invoice_level_taxes() must return false when no taxes exist.');
    }

    #[Test]
    public function it_shows_warning_in_invoice_view_when_taxes_and_einvoicing_present(): void
    {
        /* Arrange */
        $clientId = $this->seedClient([
            'client_einvoicing_active' => 1,
            'client_einvoicing_version' => 'facturx',
        ]);
        $invoiceId = $this->seedInvoice($clientId);
        $this->seedInvoiceItem($invoiceId, 100, 19);
        $this->seedInvoiceTax($invoiceId, 19);

        /* Act */
        $response = $this->request('GET', "invoices/view/{$invoiceId}");

        /* Assert */
        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString(
            'invoice_level_taxes_not_supported_in_einvoice',
            $response->body(),
            'The invoice view must show the warning about invoice-level taxes.'
        );
    }

    #[Test]
    public function it_does_not_show_warning_when_no_invoice_level_taxes_present(): void
    {
        /* Arrange */
        $clientId = $this->seedClient([
            'client_einvoicing_active' => 1,
            'client_einvoicing_version' => 'facturx',
        ]);
        $invoiceId = $this->seedInvoice($clientId);
        $this->seedInvoiceItem($invoiceId, 100, 19);

        /* Act */
        $response = $this->request('GET', "invoices/view/{$invoiceId}");

        /* Assert */
        self::assertSame(200, $response->statusCode());
        self::assertStringNotContainsString(
            'invoice_level_taxes_not_supported_in_einvoice',
            $response->body(),
            'The warning must not appear when no invoice-level taxes exist.'
        );
    }

    #[Test]
    public function it_does_not_show_warning_when_einvoicing_inactive(): void
    {
        /* Arrange */
        $clientId = $this->seedClient();
        $invoiceId = $this->seedInvoice($clientId);
        $this->seedInvoiceTax($invoiceId, 19);

        /* Act */
        $response = $this->request('GET', "invoices/view/{$invoiceId}");

        /* Assert */
        self::assertSame(200, $response->statusCode());
        self::assertStringNotContainsString(
            'invoice_level_taxes_not_supported_in_einvoice',
            $response->body(),
            'The warning must not appear when e-invoicing is not active.'
        );
    }

    protected function seedInvoiceTax(int $invoice_id, float $tax_rate): int
    {
        return $this->databaseInsert('ip_invoice_tax_rates', [
            'invoice_id' => $invoice_id,
            'invoice_tax_rate_name' => 'VAT',
            'invoice_tax_rate_percent' => $tax_rate,
            'invoice_tax_rate_amount' => 0,
            'include_item_tax' => 0,
        ]);
    }
}
