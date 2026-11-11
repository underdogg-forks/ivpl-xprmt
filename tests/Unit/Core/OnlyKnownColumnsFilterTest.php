<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\AbstractTestCase;
use Tests\Concerns\UsesCodeIgniterModels;

/**
 * MY_Model::only_known_columns() is the allow-list that Mdl_Items::save() / Mdl_Quote_Items::save() apply before
 * inserting a request-decoded item into the database (CWE-915, reported by Rick Weng / @rickwengdev; see
 * ItemSaveColumnInjectionTest for the end-to-end reproduction). This drives it directly to pin the edge cases
 * the HTTP-level test cannot reach: a null/scalar $db_array, and an object whose keys are not all real columns.
 */
#[Group('security')]
final class OnlyKnownColumnsFilterTest extends AbstractTestCase
{
    use UsesCodeIgniterModels;

    protected function tearDown(): void
    {
        $this->tearDownCodeIgniter();
        parent::tearDown();
    }

    #[Test]
    public function it_drops_a_key_that_is_not_a_real_column_of_the_items_table(): void
    {
        /* Arrange */
        $model    = $this->itemsModel();
        $db_array = (object) ['invoice_id' => 1, 'item_name' => 'Widget', 'item_description) VALUES (1)-- -' => 'x'];

        /* Act */
        $filtered = $this->invoke($model, $db_array);

        /* Assert: only real columns survive, and the attacker-chosen key is gone outright */
        self::assertSame(['invoice_id' => 1, 'item_name' => 'Widget'], (array) $filtered);
    }

    #[Test]
    public function it_keeps_every_key_when_all_of_them_are_real_columns(): void
    {
        /* Arrange: positive control, a legitimate save must not lose any of its data */
        $model    = $this->itemsModel();
        $db_array = (object) ['invoice_id' => 1, 'item_name' => 'Widget', 'item_price' => '10.00'];

        /* Act */
        $filtered = $this->invoke($model, $db_array);

        /* Assert */
        self::assertSame(['invoice_id' => 1, 'item_name' => 'Widget', 'item_price' => '10.00'], (array) $filtered);
    }

    #[Test]
    public function it_filters_a_plain_array_the_same_way_as_an_object(): void
    {
        /* Arrange */
        $model    = $this->itemsModel();
        $db_array = ['invoice_id' => 1, 'item_name' => 'Widget', 'not_a_column) VALUES (1)-- -' => 'x'];

        /* Act */
        $filtered = $this->invoke($model, $db_array);

        /* Assert */
        self::assertSame(['invoice_id' => 1, 'item_name' => 'Widget'], $filtered);
    }

    #[Test]
    public function it_returns_a_null_db_array_unchanged(): void
    {
        /* Arrange: Mdl_Items::save($id, null) takes the "update, no column changes" path in MY_Model::save() */
        $model = $this->itemsModel();

        /* Act */
        $filtered = $this->invoke($model, null);

        /* Assert */
        self::assertNull($filtered);
    }

    private function itemsModel(): object
    {
        $this->bootCodeIgniter();

        return $this->ciModel('invoices/models/Mdl_items');
    }

    private function invoke(object $model, $db_array)
    {
        $method = new ReflectionMethod($model, 'only_known_columns');
        $method->setAccessible(true);

        return $method->invoke($model, $db_array);
    }
}
