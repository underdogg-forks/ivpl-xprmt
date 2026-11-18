<?php

namespace Tests\Feature\Quotes;

use Mdl_Quote_Item_Amounts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * Mdl_Quote_Item_Amounts::calculate() with item_price_includes_tax set (GH issue #1887): item_price
 * is then the final, tax-inclusive price, and the base price/tax are derived from it so the typed
 * final price survives exactly instead of landing a cent either side of it. Mirrors
 * tests/Feature/Invoices/InvoiceItemPriceIncludesTaxTest.php for the quotes editor.
 */
#[CoversClass(Mdl_Quote_Item_Amounts::class)]
final class QuoteItemPriceIncludesTaxTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    #[Test]
    public function it_derives_the_exact_base_price_and_tax_from_a_tax_inclusive_price(): void
    {
        /* Arrange: EUR 100.00 final price at 21% VAT -> tax 17.36, base 82.64, total back to 100.00 exactly */
        $vat     = $this->taxRate('VAT 21', '21.00');
        $quoteId = $this->quote();

        /* Act */
        $this->save($quoteId, [$this->item($quoteId, 'Final price', '1', '100.00', $vat, true)]);

        /* Assert */
        $this->assertItemAmounts($quoteId, 'Final price', subtotal: 82.64, tax: 17.36, total: 100.00);
    }

    #[Test]
    public function it_still_adds_tax_on_top_when_the_flag_is_not_set(): void
    {
        /* Arrange: the same inputs, but item_price is the old tax-exclusive base price */
        $vat     = $this->taxRate('VAT 21', '21.00');
        $quoteId = $this->quote();

        /* Act */
        $this->save($quoteId, [$this->item($quoteId, 'Base price', '1', '100.00', $vat, false)]);

        /* Assert: 100 + 21% = 121.00, proving the flag (not the rate or price) drives the split */
        $this->assertItemAmounts($quoteId, 'Base price', subtotal: 100.00, tax: 21.00, total: 121.00);
    }

    #[Test]
    public function it_keeps_the_exact_final_price_with_an_integer_quantity_greater_than_one(): void
    {
        /* Arrange: 3 x EUR 100.00 final price at 21% -> per unit tax 17.36, base 82.64 */
        $vat     = $this->taxRate('VAT 21', '21.00');
        $quoteId = $this->quote();

        /* Act */
        $this->save($quoteId, [$this->item($quoteId, 'Bulk', '3', '100.00', $vat, true)]);

        /* Assert: 3 x 82.64 = 247.92 subtotal, 3 x 17.36 = 52.08 tax, 300.00 total - not 299.98 or 300.02 */
        $this->assertItemAmounts($quoteId, 'Bulk', subtotal: 247.92, tax: 52.08, total: 300.00);
    }

    #[Test]
    public function it_keeps_the_exact_final_price_under_non_legacy_calculation_too(): void
    {
        /* Arrange: einvoicing on + legacy_calculation=0 switches Mdl_Quote_Item_Amounts to the discount-aware branch */
        $this->setSetting('einvoicing', '1');
        $vat     = $this->taxRate('VAT 21', '21.00');
        $quoteId = $this->quote();

        /* Act */
        $this->save($quoteId, [$this->item($quoteId, 'Final price', '1', '100.00', $vat, true)], ['legacy_calculation' => '0']);

        /* Assert: same exact split as the legacy-mode test above */
        $this->assertItemAmounts($quoteId, 'Final price', subtotal: 82.64, tax: 17.36, total: 100.00);
    }

    #[Test]
    public function it_behaves_like_the_base_price_when_there_is_no_tax_rate(): void
    {
        /* Arrange: flag set, but no tax rate selected - nothing to split out */
        $quoteId = $this->quote();

        /* Act */
        $this->save($quoteId, [$this->item($quoteId, 'No tax', '1', '100.00', null, true)]);

        /* Assert */
        $this->assertItemAmounts($quoteId, 'No tax', subtotal: 100.00, tax: 0.00, total: 100.00);
    }

    #[Test]
    public function it_persists_the_flag_on_the_item_row(): void
    {
        /* Arrange */
        $vat     = $this->taxRate('VAT 21', '21.00');
        $quoteId = $this->quote();

        /* Act */
        $this->save($quoteId, [
            $this->item($quoteId, 'Checked', '1', '100.00', $vat, true),
            $this->item($quoteId, 'Unchecked', '1', '100.00', $vat, false),
        ]);

        /* Assert */
        $this->assertDatabaseHas('ip_quote_items', ['quote_id' => $quoteId, 'item_name' => 'Checked', 'item_price_includes_tax' => 1]);
        $this->assertDatabaseHas('ip_quote_items', ['quote_id' => $quoteId, 'item_name' => 'Unchecked', 'item_price_includes_tax' => 0]);
    }

    // -------------------------------------------------------------------------

    private function quote(): int
    {
        $quoteId = $this->databaseInsert('ip_quotes', [
            'user_id'            => 1, 'client_id' => $this->seedClient(), 'invoice_group_id' => 1, 'quote_status_id' => 2,
            'quote_number'       => 'QPIT-' . bin2hex(random_bytes(3)), 'quote_url_key' => bin2hex(random_bytes(16)),
            'quote_date_created' => date('Y-m-d'), 'quote_date_modified' => date('Y-m-d H:i:s'),
            'quote_date_expires' => date('Y-m-d', strtotime('+15 days')),
        ]);
        $this->databaseInsert('ip_quote_amounts', [
            'quote_id' => $quoteId, 'quote_item_subtotal' => '0.00', 'quote_item_tax_total' => '0.00', 'quote_tax_total' => '0.00', 'quote_total' => '0.00',
        ]);

        return $quoteId;
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
    private function item(int $quoteId, string $name, string $quantity, string $price, ?int $taxRateId, bool $priceIncludesTax): array
    {
        return [
            'quote_id'             => (string) $quoteId, 'item_id' => '', 'item_name' => $name, 'item_description' => '',
            'item_quantity'        => $quantity, 'item_price' => $price, 'item_price_includes_tax' => $priceIncludesTax,
            'item_discount_amount' => '', 'item_product_id' => '', 'item_product_unit_id' => '',
            'item_tax_rate_id'     => (string) ($taxRateId ?? 0),
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,string>      $overrides
     */
    private function save(int $quoteId, array $items, array $overrides = []): void
    {
        $response = $this->ajax('POST', '/quotes/ajax/save', array_merge([
            'quote_id'           => (string) $quoteId, 'quote_status_id' => '2', 'quote_number' => 'QPIT-' . $quoteId,
            'quote_date_created' => date('Y-m-d'), 'quote_date_expires' => date('Y-m-d', strtotime('+15 days')),
            'quote_password'     => '', 'notes' => '', 'quote_discount_percent' => '0', 'quote_discount_amount' => '0',
            'service_id'         => '', 'items' => json_encode($items),
        ], $overrides));

        self::assertSame(1, json_decode($response->body(), true)['success'] ?? null, 'Save failed: ' . $response->body());
    }

    private function assertItemAmounts(int $quoteId, string $itemName, float $subtotal, float $tax, float $total): void
    {
        $item = $this->databaseFetchOne('ip_quote_items', ['quote_id' => $quoteId, 'item_name' => $itemName]);
        self::assertNotNull($item, 'No ip_quote_items row for ' . $itemName);

        $row = $this->databaseFetchOne('ip_quote_item_amounts', ['item_id' => $item['item_id']]);
        self::assertNotNull($row, 'No ip_quote_item_amounts row for ' . $itemName);
        self::assertEqualsWithDelta($subtotal, (float) $row['item_subtotal'], 0.001, 'item subtotal');
        self::assertEqualsWithDelta($tax, (float) $row['item_tax_total'], 0.001, 'item tax total');
        self::assertEqualsWithDelta($total, (float) $row['item_total'], 0.001, 'item total');
    }
}
