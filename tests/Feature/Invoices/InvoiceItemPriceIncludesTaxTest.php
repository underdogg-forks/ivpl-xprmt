<?php

namespace Tests\Feature\Invoices;

use Mdl_Item_Amounts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * Mdl_Item_Amounts::calculate() with item_price_includes_tax set (GH issue #1887): item_price is
 * then the final, tax-inclusive price, and the base price/tax are derived from it so the typed
 * final price survives exactly instead of landing a cent either side of it.
 */
#[CoversClass(Mdl_Item_Amounts::class)]
final class InvoiceItemPriceIncludesTaxTest extends AbstractTestCase
{
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->clientId = $this->seedClient();
    }

    #[Test]
    public function it_derives_the_exact_base_price_and_tax_from_a_tax_inclusive_price(): void
    {
        /* Arrange: EUR 100.00 final price at 21% VAT -> tax 17.36, base 82.64, total back to 100.00 exactly */
        $vat       = $this->taxRate('VAT 21', '21.00');
        $invoiceId = $this->invoice();

        /* Act */
        $this->save($invoiceId, [$this->item($invoiceId, 'Final price', '1', '100.00', $vat, true)]);

        /* Assert */
        $this->assertItemAmounts($invoiceId, 'Final price', subtotal: 82.64, tax: 17.36, total: 100.00);
    }

    #[Test]
    public function it_still_adds_tax_on_top_when_the_flag_is_not_set(): void
    {
        /* Arrange: the same inputs, but item_price is the old tax-exclusive base price */
        $vat       = $this->taxRate('VAT 21', '21.00');
        $invoiceId = $this->invoice();

        /* Act */
        $this->save($invoiceId, [$this->item($invoiceId, 'Base price', '1', '100.00', $vat, false)]);

        /* Assert: 100 + 21% = 121.00, proving the flag (not the rate or price) drives the split */
        $this->assertItemAmounts($invoiceId, 'Base price', subtotal: 100.00, tax: 21.00, total: 121.00);
    }

    #[Test]
    public function it_keeps_the_exact_final_price_with_an_integer_quantity_greater_than_one(): void
    {
        /* Arrange: 3 x EUR 100.00 final price at 21% -> per unit tax 17.36, base 82.64 */
        $vat       = $this->taxRate('VAT 21', '21.00');
        $invoiceId = $this->invoice();

        /* Act */
        $this->save($invoiceId, [$this->item($invoiceId, 'Bulk', '3', '100.00', $vat, true)]);

        /* Assert: 3 x 82.64 = 247.92 subtotal, 3 x 17.36 = 52.08 tax, 300.00 total - not 299.98 or 300.02 */
        $this->assertItemAmounts($invoiceId, 'Bulk', subtotal: 247.92, tax: 52.08, total: 300.00);
    }

    #[Test]
    public function it_keeps_the_exact_final_price_under_non_legacy_calculation_too(): void
    {
        /* Arrange: einvoicing on + legacy_calculation=0 switches Mdl_Item_Amounts to the discount-aware branch */
        $this->setSetting('einvoicing', '1');
        $vat       = $this->taxRate('VAT 21', '21.00');
        $invoiceId = $this->invoice();

        /* Act */
        $this->save($invoiceId, [$this->item($invoiceId, 'Final price', '1', '100.00', $vat, true)], ['legacy_calculation' => '0']);

        /* Assert: same exact split as the legacy-mode test above */
        $this->assertItemAmounts($invoiceId, 'Final price', subtotal: 82.64, tax: 17.36, total: 100.00);
    }

    #[Test]
    public function it_behaves_like_the_base_price_when_there_is_no_tax_rate(): void
    {
        /* Arrange: flag set, but no tax rate selected - nothing to split out */
        $invoiceId = $this->invoice();

        /* Act */
        $this->save($invoiceId, [$this->item($invoiceId, 'No tax', '1', '100.00', null, true)]);

        /* Assert */
        $this->assertItemAmounts($invoiceId, 'No tax', subtotal: 100.00, tax: 0.00, total: 100.00);
    }

    #[Test]
    public function it_persists_the_flag_on_the_item_row(): void
    {
        /* Arrange */
        $vat       = $this->taxRate('VAT 21', '21.00');
        $invoiceId = $this->invoice();

        /* Act */
        $this->save($invoiceId, [
            $this->item($invoiceId, 'Checked', '1', '100.00', $vat, true),
            $this->item($invoiceId, 'Unchecked', '1', '100.00', $vat, false),
        ]);

        /* Assert */
        $this->assertDatabaseHas('ip_invoice_items', ['invoice_id' => $invoiceId, 'item_name' => 'Checked', 'item_price_includes_tax' => 1]);
        $this->assertDatabaseHas('ip_invoice_items', ['invoice_id' => $invoiceId, 'item_name' => 'Unchecked', 'item_price_includes_tax' => 0]);
    }

    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $overrides */
    private function invoice(array $overrides = []): int
    {
        return $this->seedInvoice($this->clientId, $overrides);
    }

    private function taxRate(string $name, string $percent): int
    {
        return $this->databaseInsert('ip_tax_rates', ['tax_rate_name' => $name, 'tax_rate_percent' => $percent]);
    }

    private function setSetting(string $key, string $value): void
    {
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => $key, 'setting_value' => $value]);
        $this->databaseUpdate('ip_settings', ['setting_value' => $value], ['setting_key' => $key]);
    }

    /** @return array<string,mixed> */
    private function item(int $invoiceId, string $name, string $quantity, string $price, ?int $taxRateId, bool $priceIncludesTax): array
    {
        return [
            'invoice_id'           => (string) $invoiceId, 'item_id' => '', 'item_name' => $name, 'item_description' => '',
            'item_quantity'        => $quantity, 'item_price' => $price, 'item_price_includes_tax' => $priceIncludesTax,
            'item_discount_amount' => '', 'item_product_id' => '', 'item_product_unit_id' => '',
            'item_tax_rate_id'     => (string) ($taxRateId ?? 0),
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,string>      $overrides
     */
    private function save(int $invoiceId, array $items, array $overrides = []): void
    {
        $response = $this->ajax('POST', '/invoices/ajax/save', array_merge([
            'invoice_id'               => (string) $invoiceId, 'invoice_date_created' => date('Y-m-d'),
            'invoice_date_due'         => date('Y-m-d', strtotime('+30 days')), 'invoice_time_created' => date('H:i:s'),
            'invoice_status_id'        => (string) $this->databaseFetchOne('ip_invoices', ['invoice_id' => $invoiceId])['invoice_status_id'],
            'invoice_discount_percent' => '0', 'invoice_discount_amount' => '0', 'items' => json_encode($items),
        ], $overrides));

        self::assertSame(1, json_decode($response->body(), true)['success'] ?? null, 'Save failed: ' . $response->body());
    }

    private function assertItemAmounts(int $invoiceId, string $itemName, float $subtotal, float $tax, float $total): void
    {
        $item = $this->databaseFetchOne('ip_invoice_items', ['invoice_id' => $invoiceId, 'item_name' => $itemName]);
        self::assertNotNull($item, 'No ip_invoice_items row for ' . $itemName);

        $row = $this->databaseFetchOne('ip_invoice_item_amounts', ['item_id' => $item['item_id']]);
        self::assertNotNull($row, 'No ip_invoice_item_amounts row for ' . $itemName);
        self::assertEqualsWithDelta($subtotal, (float) $row['item_subtotal'], 0.001, 'item subtotal');
        self::assertEqualsWithDelta($tax, (float) $row['item_tax_total'], 0.001, 'item tax total');
        self::assertEqualsWithDelta($total, (float) $row['item_total'], 0.001, 'item total');
    }
}
