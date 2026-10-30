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
        $this->setUpDatabase();
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
        $itemId = $this->databaseInsert('ip_invoice_items', [
            'invoice_id' => $invoiceId,
            'item_name' => 'Test Item',
            'item_description' => '',
            'item_quantity' => '1',
            'item_price' => '100.00',
            'item_order' => 1,
            'item_date_added' => date('Y-m-d'),
        ]);
        $this->databaseInsert('ip_invoice_item_amounts', [
            'item_id' => $itemId,
            'item_subtotal' => '100.00',
            'item_tax_total' => '19.00',
            'item_discount' => '0.00',
            'item_total' => '119.00',
        ]);
        $this->databaseInsert('ip_invoice_tax_rates', [
            'invoice_id' => $invoiceId,
            'invoice_tax_rate_name' => 'VAT',
            'invoice_tax_rate_percent' => 19,
            'invoice_tax_rate_amount' => 19,
            'include_item_tax' => 0,
        ]);

        /* Act */
        $response = $this->get("invoices/view/{$invoiceId}");

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
        $itemId = $this->databaseInsert('ip_invoice_items', [
            'invoice_id' => $invoiceId,
            'item_name' => 'Test Item',
            'item_description' => '',
            'item_quantity' => '1',
            'item_price' => '100.00',
            'item_order' => 1,
            'item_date_added' => date('Y-m-d'),
        ]);
        $this->databaseInsert('ip_invoice_item_amounts', [
            'item_id' => $itemId,
            'item_subtotal' => '100.00',
            'item_tax_total' => '19.00',
            'item_discount' => '0.00',
            'item_total' => '119.00',
        ]);

        /* Act */
        $response = $this->get("invoices/view/{$invoiceId}");

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
        $this->databaseInsert('ip_invoice_tax_rates', [
            'invoice_id' => $invoiceId,
            'invoice_tax_rate_name' => 'VAT',
            'invoice_tax_rate_percent' => 19,
            'invoice_tax_rate_amount' => 19,
            'include_item_tax' => 0,
        ]);

        /* Act */
        $response = $this->get("invoices/view/{$invoiceId}");

        /* Assert */
        self::assertSame(200, $response->statusCode());
        self::assertStringNotContainsString(
            'invoice_level_taxes_not_supported_in_einvoice',
            $response->body(),
            'The warning must not appear when e-invoicing is not active.'
        );
    }
}
