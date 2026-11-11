<?php

namespace Tests\Feature\Core\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * `invoices/ajax/save` and `quotes/ajax/save` decode the posted `items` JSON and pass each item object straight to
 * the query builder's `insert()`. Every property name becomes a column name, with no allow-list, and CodeIgniter
 * does not escape an identifier that contains a parenthesis (DB_driver::_escape_identifiers()), so a crafted
 * property name can close the column list and supply its own `VALUES` clause, including a subquery — any
 * logged-in administrator can read arbitrary database content this way and see it on their own invoice/quote page.
 *
 * The reproduction below reads a harmless marker value seeded in `ip_settings`, never a real secret such as a
 * password hash, by the same mechanism the report demonstrates.
 *
 * Reported by Rick Weng (@rickwengdev).
 */
#[Group('security')]
final class ItemSaveColumnInjectionTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    #[Test]
    public function it_does_not_let_an_item_property_name_inject_a_column_list_and_values_clause_on_an_invoice(): void
    {
        /* Arrange: a harmless marker value this request has no business reading, reached only through a subquery
         * smuggled in via the item property name. */
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'repro_marker_setting', 'setting_value' => 'repro-marker-value']);
        $invoiceId    = $this->seedInvoice($this->seedClient(), ['invoice_number' => 'colinj-inv']);
        $maliciousKey = "item_description) VALUES ({$invoiceId},'colinj-marker',1,1,NULL,NULL,NULL,"
            . "(SELECT setting_value FROM ip_settings WHERE setting_key='repro_marker_setting'))-- -";

        /* Act */
        $response = $this->ajax('POST', '/invoices/ajax/save', [
            'invoice_id'           => (string) $invoiceId,
            'invoice_number'       => 'colinj-inv',
            'invoice_date_created' => date('Y-m-d'),
            'invoice_date_due'     => date('Y-m-d'),
            'invoice_time_created' => date('H:i:s'),
            'invoice_status_id'    => '1',
            'items'                => json_encode([[
                'invoice_id'           => (string) $invoiceId,
                'item_id'              => '',
                'item_task_id'         => '',
                'item_name'            => 'colinj-legit',
                'item_quantity'        => '1',
                'item_price'           => '1',
                'item_discount_amount' => '',
                'item_product_id'      => '',
                'item_product_unit_id' => '',
                $maliciousKey          => '1',
            ]]),
        ]);

        /* Assert: no row carries the leaked marker value, and the request did not crash the way an unknown
         * column name would (proving the key reached the query as a column, not merely as a rejected field) */
        $this->assertResponseStatusCode($response, 200);
        $this->assertDatabaseMissing('ip_invoice_items', ['item_description' => 'repro-marker-value']);
        $this->assertDatabaseMissing('ip_invoice_items', ['item_name' => 'colinj-marker']);
        $this->assertDatabaseHas('ip_invoice_items', ['invoice_id' => $invoiceId, 'item_name' => 'colinj-legit']);
    }

    #[Test]
    public function it_does_not_let_an_item_property_name_inject_a_column_list_and_values_clause_on_a_quote(): void
    {
        /* Arrange */
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'repro_marker_setting', 'setting_value' => 'repro-marker-value']);
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'quotes_expire_after', 'setting_value' => '15']);
        $quoteId      = $this->seedQuote($this->seedClient(), ['quote_number' => 'colinj-quo']);
        $maliciousKey = "item_description) VALUES ({$quoteId},'colinj-marker',1,1,NULL,NULL,NULL,"
            . "(SELECT setting_value FROM ip_settings WHERE setting_key='repro_marker_setting'))-- -";

        /* Act */
        $response = $this->ajax('POST', '/quotes/ajax/save', [
            'quote_id'           => (string) $quoteId,
            'quote_number'       => 'colinj-quo',
            'quote_date_created' => date('Y-m-d'),
            'quote_date_expires' => date('Y-m-d', strtotime('+15 days')),
            'quote_status_id'    => '1',
            'items'              => json_encode([[
                'quote_id'             => (string) $quoteId,
                'item_id'              => '',
                'item_name'            => 'colinj-legit',
                'item_quantity'        => '1',
                'item_price'           => '1',
                'item_discount_amount' => '',
                'item_product_id'      => '',
                'item_product_unit_id' => '',
                $maliciousKey          => '1',
            ]]),
        ]);

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertDatabaseMissing('ip_quote_items', ['item_description' => 'repro-marker-value']);
        $this->assertDatabaseMissing('ip_quote_items', ['item_name' => 'colinj-marker']);
        $this->assertDatabaseHas('ip_quote_items', ['quote_id' => $quoteId, 'item_name' => 'colinj-legit']);
    }

    private function seedQuote(int $clientId, array $overrides = []): int
    {
        $quoteId = $this->databaseInsert('ip_quotes', array_merge([
            'user_id'            => 1, 'client_id' => $clientId, 'invoice_group_id' => 1, 'quote_status_id' => 2,
            'quote_number'       => 'QUO-' . bin2hex(random_bytes(3)), 'quote_url_key' => bin2hex(random_bytes(16)),
            'quote_date_created' => date('Y-m-d'), 'quote_date_modified' => date('Y-m-d H:i:s'),
            'quote_date_expires' => date('Y-m-d', strtotime('+15 days')),
        ], $overrides));
        $this->databaseInsert('ip_quote_amounts', [
            'quote_id'        => $quoteId, 'quote_item_subtotal' => '0.00', 'quote_item_tax_total' => '0.00',
            'quote_tax_total' => '0.00', 'quote_total' => '0.00',
        ]);

        return $quoteId;
    }
}
