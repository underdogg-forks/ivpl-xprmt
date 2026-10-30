<?php

namespace Tests\Unit\EInvoicing;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\UsesCodeIgniterModels;

#[Group('einvoicing')]
final class InvoiceLevelTaxesDetectionTest extends AbstractTestCase
{
    use UsesCodeIgniterModels;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCodeIgniter();
    }

    #[Test]
    public function it_detects_when_invoice_has_invoice_level_taxes(): void
    {
        /* Arrange: create invoice with invoice-level tax */
        $clientId = $this->seedClient();
        $invoiceId = $this->seedInvoice($clientId);
        $taxRateId = $this->insertTaxRate('VAT', 19);
        $this->databaseInsert('ip_invoice_tax_rates', [
            'invoice_id' => $invoiceId,
            'tax_rate_id' => $taxRateId,
            'invoice_tax_rate_amount' => 19,
            'include_item_tax' => 0,
        ]);

        /* Helper must be loaded to use the function */
        require_once APPPATH . 'helpers/e-invoice_helper.php';

        /* Act */
        $result = invoice_has_invoice_level_taxes($invoiceId);

        /* Assert */
        self::assertTrue($result, 'Should detect invoice-level taxes when ip_invoice_tax_rates has a row.');
    }

    #[Test]
    public function it_returns_false_when_no_invoice_level_taxes_exist(): void
    {
        /* Arrange: create invoice without invoice-level tax */
        $clientId = $this->seedClient();
        $invoiceId = $this->seedInvoice($clientId);

        /* Helper must be loaded to use the function */
        require_once APPPATH . 'helpers/e-invoice_helper.php';

        /* Act */
        $result = invoice_has_invoice_level_taxes($invoiceId);

        /* Assert */
        self::assertFalse($result, 'Should return false when no rows exist in ip_invoice_tax_rates.');
    }

    #[Test]
    public function it_handles_nonexistent_invoice_gracefully(): void
    {
        /* Arrange */
        require_once APPPATH . 'helpers/e-invoice_helper.php';

        /* Act */
        $result = invoice_has_invoice_level_taxes(999999);

        /* Assert */
        self::assertFalse($result, 'Should return false for non-existent invoices.');
    }

    /**
     * Helper to insert a tax rate and return its ID.
     */
    private function insertTaxRate(string $name, float $percent): int
    {
        $CI = &get_instance();
        $CI->db->insert('ip_tax_rates', [
            'tax_rate_name' => $name,
            'tax_rate_percent' => $percent,
        ]);

        return (int) $CI->db->insert_id();
    }
}
