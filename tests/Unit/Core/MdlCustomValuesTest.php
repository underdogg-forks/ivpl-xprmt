<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\UsesCodeIgniterModels;

final class MdlCustomValuesTest extends AbstractTestCase
{
    use UsesCodeIgniterModels;

    protected function tearDown(): void
    {
        $this->tearDownCodeIgniter();
        parent::tearDown();
    }

    #[Test]
    public function it_forgets_grouped_after_the_query_so_a_later_plain_query_orders_by_value_again(): void
    {
        /* Arrange: two values inserted in reverse alphabetical order */
        $fieldId = $this->databaseInsert('ip_custom_fields', [
            'custom_field_table' => 'ip_client_custom', 'custom_field_label' => 'Reset', 'custom_field_type' => 'SINGLE-CHOICE',
            'custom_field_order' => 1, 'custom_field_location' => 0,
        ]);
        $this->databaseInsert('ip_custom_values', ['custom_values_field' => $fieldId, 'custom_values_value' => 'zeta']);
        $this->databaseInsert('ip_custom_values', ['custom_values_field' => $fieldId, 'custom_values_value' => 'alpha']);
        $ci = $this->bootCodeIgniter();
        $ci->load->model('custom_values/mdl_custom_values');
        $model = $ci->mdl_custom_values;

        /* Act: a grouped query first, then an ordinary one on the same instance */
        $grouped = $model->grouped()->get()->row();
        $plain   = array_column($model->get()->result_array(), 'custom_values_value');

        /* Assert: the grouped list selects no value column, and the ordinary query is ordered by value again
           (while grouped() is active the order is by field table and label, which leaves insertion order here) */
        self::assertFalse(property_exists($grouped, 'custom_values_value'));
        self::assertSame(['alpha', 'zeta'], $plain);
    }
}
