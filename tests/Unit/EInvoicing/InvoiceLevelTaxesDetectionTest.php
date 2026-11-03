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
        require_once APPPATH . 'helpers/e-invoice_helper.php';
    }

    protected function tearDown(): void
    {
        $this->tearDownCodeIgniter();
        parent::tearDown();
    }

    #[Test]
    public function it_detects_when_invoice_has_invoice_level_taxes(): void
    {
        /* Arrange: create invoice with invoice-level tax */
        $clientId  = $this->seedClient();
        $invoiceId = $this->seedInvoice($clientId);
        $taxRateId = $this->insertTaxRate('VAT', 19);
        $this->databaseInsert('ip_invoice_tax_rates', [
            'invoice_id'              => $invoiceId,
            'tax_rate_id'             => $taxRateId,
            'invoice_tax_rate_amount' => 19,
            'include_item_tax'        => 0,
        ]);

        /* Act */
        $result = invoice_has_invoice_level_taxes($invoiceId);

        /* Assert */
        self::assertTrue($result, 'Should detect invoice-level taxes when ip_invoice_tax_rates has a row.');
    }

    #[Test]
    public function it_returns_false_when_no_invoice_level_taxes_exist(): void
    {
        /* Arrange: create invoice without invoice-level tax */
        $clientId  = $this->seedClient();
        $invoiceId = $this->seedInvoice($clientId);

        /* Act */
        $result = invoice_has_invoice_level_taxes($invoiceId);

        /* Assert */
        self::assertFalse($result, 'Should return false when no rows exist in ip_invoice_tax_rates.');
    }

    #[Test]
    public function it_handles_nonexistent_invoice_gracefully(): void
    {
        /* Arrange: no such invoice */

        /* Act */
        $result = invoice_has_invoice_level_taxes(999999);

        /* Assert */
        self::assertFalse($result, 'Should return false for non-existent invoices.');
    }

    #[Test]
    public function it_does_not_count_the_taxes_of_another_invoice(): void
    {
        /* Arrange: only the first invoice has an invoice-level tax */
        $taxed = $this->seedInvoice($this->seedClient());
        $other = $this->seedInvoice($this->seedClient());
        $this->databaseInsert('ip_invoice_tax_rates', ['invoice_id' => $taxed, 'tax_rate_id' => $this->insertTaxRate('VAT', 19), 'invoice_tax_rate_amount' => 19, 'include_item_tax' => 0]);

        /* Act */
        $result = invoice_has_invoice_level_taxes($other);

        /* Assert: positive control on the taxed invoice, then the other one */
        self::assertTrue(invoice_has_invoice_level_taxes($taxed));
        self::assertFalse($result);
    }

    #[Test]
    public function it_still_detects_a_tax_whose_tax_rate_was_deleted(): void
    {
        /* Arrange: the stored invoice tax points at a tax rate that no longer exists */
        $invoiceId = $this->seedInvoice($this->seedClient());
        $this->databaseInsert('ip_invoice_tax_rates', ['invoice_id' => $invoiceId, 'tax_rate_id' => 987654, 'invoice_tax_rate_amount' => 19, 'include_item_tax' => 0]);

        /* Act */
        $result = invoice_has_invoice_level_taxes($invoiceId);

        /* Assert: the row still changes the PDF total, so it must still block the XML */
        self::assertTrue($result);
    }

    #[Test]
    public function it_detects_an_invoice_with_several_invoice_level_taxes(): void
    {
        /* Arrange */
        $invoiceId = $this->seedInvoice($this->seedClient());
        foreach ([['VAT', 19], ['Levy', 2]] as [$name, $percent]) {
            $this->databaseInsert('ip_invoice_tax_rates', ['invoice_id' => $invoiceId, 'tax_rate_id' => $this->insertTaxRate($name, $percent), 'invoice_tax_rate_amount' => 5, 'include_item_tax' => 0]);
        }

        /* Act */
        $result = invoice_has_invoice_level_taxes($invoiceId);

        /* Assert */
        self::assertTrue($result);
    }

    /**
     * Helper to insert a tax rate and return its ID.
     */
    private function insertTaxRate(string $name, float $percent): int
    {
        $CI = &get_instance();
        $CI->db->insert('ip_tax_rates', [
            'tax_rate_name'    => $name,
            'tax_rate_percent' => $percent,
        ]);

        return (int) $CI->db->insert_id();
    }
}
