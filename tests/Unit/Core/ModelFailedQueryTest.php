<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\UsesCodeIgniterModels;

/**
 * With db_debug off (production) CI's get() returns false for a query the database rejects. MY_Model::result()
 * and its siblings called ->result() on that false: a fatal "member function on false" and a blank HTTP 500
 * (the Custom Values page under ONLY_FULL_GROUP_BY). They now stop the request with a proper 500 error page
 * (ModelFailedQueryRequestTest). This class covers the model side: what a rejected query looks like, that real and
 * empty results are untouched, and that grouped() does not leak into the next query.
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
    public function it_marks_a_rejected_query_as_failed_rather_than_empty(): void
    {
        /* Arrange: production setting (db_debug off) and a query the database rejects (unknown column) */
        $ci               = $this->bootCodeIgniter();
        $ci->db->db_debug = false;
        $ci->load->model('custom_values/mdl_custom_values');
        $model = $ci->mdl_custom_values;

        /* Act */
        $model->where('no_such_column', 1)->get();

        /* Assert: the premise of the request-level tests: the model holds false, not an empty result.
           What the accessors then do (stop with a 500) is covered through a real request in
           ModelFailedQueryRequestTest, because show_error() ends the process in this in-process harness. */
        self::assertFalse($model->query);
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

    #[Test]
    public function it_returns_an_empty_result_for_a_query_that_works_but_matches_nothing(): void
    {
        /* Arrange */
        $ci               = $this->bootCodeIgniter();
        $ci->db->db_debug = false;
        $ci->load->model('custom_values/mdl_custom_values');
        $model = $ci->mdl_custom_values;

        /* Act */
        $model->where('custom_values_value', 'no such value')->get();

        /* Assert: "nothing matched" is still an ordinary empty answer, distinct from a rejected query */
        self::assertSame([], $model->result());
        self::assertNull($model->row());
        self::assertSame(0, $model->num_rows());
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
