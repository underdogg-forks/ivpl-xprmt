<?php

namespace Tests\Feature\Invoices;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * Mdl_items::delete() recalculated the invoice from the discount shares stored on the remaining
 * items. With a fixed 50.00 global discount over two 100.00 items each item carries 25.00, so after a
 * delete only 25.00 was subtracted and the invoice showed 75.00 instead of 50.00.
 *
 * Only the discount split of the e-invoice calculation mode (legacy_calculation = false) is stored per item.
 */
#[Group('invoices')]
final class DeleteItemKeepsGlobalDiscountTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'tax_rate_decimal_places', 'setting_value' => '2']);
        $this->withEnvironment(['LEGACY_CALCULATION' => 'false']);
    }

    #[Test]
    public function it_gives_the_whole_fixed_discount_to_the_remaining_item(): void
    {
        /* Arrange: two 100.00 items, 50.00 off, stored as the save path stores them (25.00 each) */
        [$invoiceId, $first, $second] = $this->invoice(['invoice_discount_amount' => '50.00'], ['100.00', '100.00'], '25.00');
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_total' => '150.00']);

        /* Act */
        $response = $this->deleteItem($invoiceId, $second);

        /* Assert */
        self::assertSame(1, json_decode($response->body(), true)['success'] ?? null, 'Body: ' . $response->body());
        $this->assertDatabaseMissing('ip_invoice_items', ['item_id' => $second]);
        $this->assertDatabaseHas('ip_invoice_amounts', [
            'invoice_id' => $invoiceId, 'invoice_item_subtotal' => '50.00', 'invoice_total' => '50.00', 'invoice_balance' => '50.00',
        ]);
        $this->assertDatabaseHas('ip_invoice_item_amounts', ['item_id' => $first, 'item_total' => '50.00']);
    }

    #[Test]
    public function it_splits_the_fixed_discount_in_proportion_to_the_items_that_are_left(): void
    {
        /* Arrange: 100.00 + 200.00 + 300.00, 60.00 off (10.00 / 20.00 / 30.00) */
        [$invoiceId, $a, $b, $c] = $this->invoice(['invoice_discount_amount' => '60.00'], ['100.00', '200.00', '300.00'], null, ['10.00', '20.00', '30.00']);

        /* Act: drop the 300.00 item; 60.00 is shared 1:2 over 100.00 and 200.00 */
        $this->deleteItem($invoiceId, $c);

        /* Assert */
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_total' => '240.00']);
        $this->assertDatabaseHas('ip_invoice_item_amounts', ['item_id' => $a, 'item_total' => '80.00']);
        $this->assertDatabaseHas('ip_invoice_item_amounts', ['item_id' => $b, 'item_total' => '160.00']);
    }

    #[Test]
    public function it_applies_the_tax_after_the_redistributed_discount(): void
    {
        /* Arrange: two 100.00 items at 20 % VAT, 50.00 off: (100 - 25) * 1.2 = 90.00 each */
        $taxRateId              = $this->databaseInsert('ip_tax_rates', ['tax_rate_name' => 'VAT20', 'tax_rate_percent' => '20.00']);
        [$invoiceId, , $second] = $this->invoice(['invoice_discount_amount' => '50.00'], ['100.00', '100.00'], '25.00', null, $taxRateId);
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_total' => '180.00']);

        /* Act */
        $this->deleteItem($invoiceId, $second);

        /* Assert: (100 - 50) * 1.2 = 60.00, not (100 - 25) * 1.2 = 90.00 */
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_item_tax_total' => '10.00', 'invoice_total' => '60.00']);
    }

    #[Test]
    public function it_keeps_a_percentage_discount_proportional(): void
    {
        /* Arrange: positive control, a percentage discount was never affected: 10 % of two 100.00 items */
        [$invoiceId, , $second] = $this->invoice(['invoice_discount_percent' => '10.00'], ['100.00', '100.00'], '10.00');
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_total' => '180.00']);

        /* Act */
        $this->deleteItem($invoiceId, $second);

        /* Assert */
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_total' => '90.00']);
    }

    #[Test]
    public function it_leaves_an_invoice_without_a_discount_alone(): void
    {
        /* Arrange */
        [$invoiceId, , $second] = $this->invoice([], ['100.00', '100.00'], '0.00');

        /* Act */
        $this->deleteItem($invoiceId, $second);

        /* Assert */
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_total' => '100.00', 'invoice_balance' => '100.00']);
    }

    #[Test]
    public function it_zeroes_the_invoice_when_the_last_item_is_deleted(): void
    {
        /* Arrange: a fixed discount must not leave a negative total behind when no item is left */
        [$invoiceId, $only] = $this->invoice(['invoice_discount_amount' => '50.00'], ['100.00'], '50.00');

        /* Act */
        $this->deleteItem($invoiceId, $only);

        /* Assert */
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_total' => '0.00']);
    }

    #[Test]
    public function it_refuses_the_delete_and_changes_nothing_when_the_discount_would_make_the_total_negative(): void
    {
        /* Arrange: 150.00 off two 100.00 items (75.00 each, total 50.00); one item alone could not carry it */
        [$invoiceId, $first, $second] = $this->invoice(['invoice_discount_amount' => '150.00'], ['100.00', '100.00'], '75.00');
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_total' => '50.00']);

        /* Act */
        $response = $this->deleteItem($invoiceId, $second);

        /* Assert: reported as a failure, and the whole delete was rolled back */
        self::assertSame(0, json_decode($response->body(), true)['success'] ?? null, 'Body: ' . $response->body());
        $this->assertDatabaseHas('ip_invoice_items', ['item_id' => $second]);
        $this->assertDatabaseHas('ip_invoice_item_amounts', ['item_id' => $second, 'item_total' => '25.00']);
        $this->assertDatabaseHas('ip_invoice_item_amounts', ['item_id' => $first, 'item_total' => '25.00']);
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_total' => '50.00', 'invoice_balance' => '50.00']);
    }

    #[Test]
    public function it_shares_the_discount_over_an_item_that_has_no_amount_row_yet(): void
    {
        /* Arrange: three 100.00 items, 60.00 off (20.00 each); the third lost its amount row, as after an interrupted save */
        [$invoiceId, $first, $second, $third] = $this->invoice(['invoice_discount_amount' => '60.00'], ['100.00', '100.00', '100.00'], '20.00');
        $this->databaseDelete('ip_invoice_item_amounts', ['item_id' => $third]);

        /* Act: delete the second; 60.00 is shared over the two items that are left */
        $response = $this->deleteItem($invoiceId, $second);

        /* Assert: 200.00 - 60.00, not 200.00 - 120.00 */
        self::assertSame(1, json_decode($response->body(), true)['success'] ?? null, 'Body: ' . $response->body());
        $this->assertDatabaseHas('ip_invoice_item_amounts', ['item_id' => $first, 'item_total' => '70.00']);
        $this->assertDatabaseHas('ip_invoice_item_amounts', ['item_id' => $third, 'item_total' => '70.00']);
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_total' => '140.00']);
    }

    /**
     * @param array<string, string> $invoice invoice columns (discounts)
     * @param list<string>          $prices  one 100.00-style subtotal per item
     * @param ?string               $share   discount share stored on every item, or
     * @param ?list<string>         $shares  one stored share per item
     *
     * @return list<int> [invoiceId, itemId...]
     */
    private function invoice(array $invoice, array $prices, ?string $share, ?array $shares = null, ?int $taxRateId = null): array
    {
        $invoiceId = $this->seedInvoice($this->seedClient(), $invoice + ['invoice_number' => 'DISC-' . bin2hex(random_bytes(3))]);
        $ids       = [$invoiceId];
        $subtotal  = 0.0;
        $taxTotal  = 0.0;
        $total     = 0.0;

        foreach ($prices as $i => $price) {
            $itemShare = (float) ($shares[$i] ?? $share);
            $tax       = $taxRateId ? round(((float) $price - $itemShare) * 0.2, 2) : 0.0;
            $itemTotal = (float) $price - $itemShare + $tax;

            $itemId = $this->databaseInsert('ip_invoice_items', [
                'invoice_id' => $invoiceId, 'item_name' => 'Line ' . ($i + 1), 'item_description' => '', 'item_quantity' => '1', 'item_price' => $price,
                'item_order' => $i + 1, 'item_date_added' => date('Y-m-d'), 'item_tax_rate_id' => $taxRateId ?? 0,
            ]);
            $this->databaseInsert('ip_invoice_item_amounts', [
                'item_id' => $itemId, 'item_subtotal' => $price, 'item_tax_total' => (string) $tax, 'item_discount' => '0.00', 'item_total' => (string) $itemTotal,
            ]);
            $subtotal += (float) $price - $itemShare;
            $taxTotal += $tax;
            $total += $itemTotal;
            $ids[] = $itemId;
        }

        $this->databaseUpdate('ip_invoice_amounts', [
            'invoice_item_subtotal' => (string) $subtotal, 'invoice_item_tax_total' => (string) $taxTotal,
            'invoice_total'         => (string) $total, 'invoice_balance' => (string) $total,
        ], ['invoice_id' => $invoiceId]);

        return $ids;
    }

    private function deleteItem(int $invoiceId, int $itemId): \Tests\Integration\Support\HttpResponse
    {
        return $this->ajax('POST', '/invoices/ajax/delete_item/' . $invoiceId, ['item_id' => (string) $itemId]);
    }
}
