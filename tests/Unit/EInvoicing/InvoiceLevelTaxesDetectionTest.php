<?php

namespace Tests\Unit\EInvoicing;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesCodeIgniterModels;
use PHPUnit\Framework\TestCase;

#[Group('einvoicing')]
final class InvoiceLevelTaxesDetectionTest extends TestCase
{
    use UsesCodeIgniterModels;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCodeIgniterDatabase();
    }

    #[Test]
    public function it_detects_when_invoice_has_invoice_level_taxes(): void
    {
        /* Arrange */
        $CI = &get_instance();
        $CI->load->helper('e-invoice');
        $CI->load->model(['invoices/mdl_invoices', 'clients/mdl_clients']);

        $clientId = $CI->databaseInsert('ip_clients', [
            'client_name' => 'Test Client',
            'client_active' => 1,
            'user_id' => 1,
        ]);

        $invoiceId = $CI->databaseInsert('ip_invoices', [
            'invoice_number' => '0001',
            'client_id' => $clientId,
            'user_id' => 1,
            'invoice_status_id' => 1,
        ]);

        $CI->databaseInsert('ip_invoice_amounts', [
            'invoice_id' => $invoiceId,
            'invoice_item_subtotal' => 100,
            'invoice_item_tax_total' => 19,
            'invoice_tax_total' => 0,
            'invoice_total' => 119,
            'invoice_paid' => 0,
            'invoice_balance' => 119,
        ]);

        $CI->databaseInsert('ip_invoice_tax_rates', [
            'invoice_id' => $invoiceId,
            'invoice_tax_rate_name' => 'VAT',
            'invoice_tax_rate_percent' => 19,
            'invoice_tax_rate_amount' => 19,
            'include_item_tax' => 0,
        ]);

        /* Act */
        $result = invoice_has_invoice_level_taxes($invoiceId);

        /* Assert */
        self::assertTrue($result, 'Should detect invoice-level taxes.');
    }

    #[Test]
    public function it_returns_false_when_no_invoice_level_taxes_exist(): void
    {
        /* Arrange */
        $CI = &get_instance();
        $CI->load->helper('e-invoice');
        $CI->load->model(['invoices/mdl_invoices', 'clients/mdl_clients']);

        $clientId = $CI->databaseInsert('ip_clients', [
            'client_name' => 'Test Client',
            'client_active' => 1,
            'user_id' => 1,
        ]);

        $invoiceId = $CI->databaseInsert('ip_invoices', [
            'invoice_number' => '0002',
            'client_id' => $clientId,
            'user_id' => 1,
            'invoice_status_id' => 1,
        ]);

        $CI->databaseInsert('ip_invoice_amounts', [
            'invoice_id' => $invoiceId,
            'invoice_item_subtotal' => 100,
            'invoice_item_tax_total' => 19,
            'invoice_tax_total' => 0,
            'invoice_total' => 119,
            'invoice_paid' => 0,
            'invoice_balance' => 119,
        ]);

        /* Act */
        $result = invoice_has_invoice_level_taxes($invoiceId);

        /* Assert */
        self::assertFalse($result, 'Should return false when no invoice-level taxes exist.');
    }

    #[Test]
    public function it_handles_nonexistent_invoice_gracefully(): void
    {
        /* Arrange */
        $CI = &get_instance();
        $CI->load->helper('e-invoice');

        /* Act */
        $result = invoice_has_invoice_level_taxes(999999);

        /* Assert */
        self::assertFalse($result, 'Should return false for non-existent invoices.');
    }
}
