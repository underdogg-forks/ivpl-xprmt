<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\UsesCodeIgniterModels;

/**
 * With db_debug off (production) CI's get() returns false for a query the database rejects. MY_Model::result()
 * and its siblings called ->result() on that false: a fatal "member function on false" and a blank HTTP 500
 * (the Custom Values page under ONLY_FULL_GROUP_BY). They now answer with an empty result.
 */
final class ModelFailedQueryTest extends AbstractTestCase
{
    use UsesCodeIgniterModels;

    protected function tearDown(): void
    {
        $this->tearDownCodeIgniter();
        parent::tearDown();
    }

    #[Test]
    public function it_answers_every_accessor_with_an_empty_value_when_the_query_fails(): void
    {
        /* Arrange: production setting, and a query the database rejects (unknown column) */
        $ci               = $this->bootCodeIgniter();
        $ci->db->db_debug = false;
        $ci->load->model('custom_values/mdl_custom_values');
        $model = $ci->mdl_custom_values;

        /* Act */
        $model->where('no_such_column', 1)->get();

        /* Assert */
        self::assertFalse($model->query, 'the premise: the database rejected the query');
        self::assertSame([], $model->result());
        self::assertNull($model->row());
        self::assertSame([], $model->result_array());
        self::assertNull($model->row_array());
        self::assertSame(0, $model->num_rows());
    }

    #[Test]
    public function it_still_returns_the_rows_of_a_query_that_works(): void
    {
        /* Arrange */
        $fieldId = $this->databaseInsert('ip_custom_fields', [
            'custom_field_table' => 'ip_client_custom', 'custom_field_label' => 'Works', 'custom_field_type' => 'SINGLE-CHOICE',
            'custom_field_order' => 1, 'custom_field_location' => 0,
        ]);
        $this->databaseInsert('ip_custom_values', ['custom_values_field' => $fieldId, 'custom_values_value' => 'present']);
        $ci               = $this->bootCodeIgniter();
        $ci->db->db_debug = false;
        $ci->load->model('custom_values/mdl_custom_values');

        /* Act */
        $model = $ci->mdl_custom_values;
        $model->where('custom_values_value', 'present')->get();

        /* Assert: the failure guard does not swallow real results */
        self::assertCount(1, $model->result());
        self::assertSame('present', $model->row()->custom_values_value);
        self::assertSame(1, $model->num_rows());
    }
}
