<?php

namespace Tests\Feature\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * A query the database rejects must stop the request with an error page, not end as a fatal "member function
 * on false" (blank HTTP 500) and not as an empty result. Callers read an empty result as "no such record":
 * Mdl_Custom_Values::used() would call a value in use "unused", and delete() would remove it.
 *
 * The rejection is produced by hiding a table while the request runs, in the production environment
 * (db_debug off) where CodeIgniter hands the model false instead of throwing.
 */
#[Group('custom_values')]
class ModelFailedQueryRequestTest extends AbstractTestCase
{
    private ?string $hiddenTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    protected function tearDown(): void
    {
        $this->showTable();
        parent::tearDown();
    }

    #[Test]
    public function it_answers_the_custom_values_page_with_a_500_error_page_when_its_query_is_rejected(): void
    {
        /* Arrange: the list query joins ip_custom_values, so hiding it makes that query fail */
        $this->hideTable('ip_custom_values');

        /* Act */
        $response = $this->get('/custom_values');

        /* Assert: a real error page with the message, not a blank body and not an empty list */
        $this->assertResponseStatusCode($response, 500);
        $this->assertResponseBodyContains($response, 'A database error occurred');
    }

    #[Test]
    public function it_does_not_delete_a_value_whose_usage_could_not_be_checked(): void
    {
        /* Arrange: a value, then a database that rejects the lookup used() makes */
        $fieldId = $this->databaseInsert('ip_custom_fields', [
            'custom_field_table' => 'ip_client_custom', 'custom_field_label' => 'Kept', 'custom_field_type' => 'SINGLE-CHOICE',
            'custom_field_order' => 1, 'custom_field_location' => 0,
        ]);
        $valueId = $this->databaseInsert('ip_custom_values', ['custom_values_field' => $fieldId, 'custom_values_value' => 'maybe in use']);
        $this->hideTable('ip_custom_fields');

        /* Act */
        $response = $this->post('/custom_values/delete/' . $valueId, []);
        $this->showTable();

        /* Assert: the request stopped with a 500 and the value survived */
        $this->assertResponseStatusCode($response, 500);
        $this->assertDatabaseHas('ip_custom_values', ['custom_values_id' => $valueId]);
    }

    #[Test]
    public function it_still_deletes_an_unused_value_when_the_database_is_healthy(): void
    {
        /* Arrange: the positive control for the test above */
        $fieldId = $this->databaseInsert('ip_custom_fields', [
            'custom_field_table' => 'ip_client_custom', 'custom_field_label' => 'Control', 'custom_field_type' => 'SINGLE-CHOICE',
            'custom_field_order' => 1, 'custom_field_location' => 0,
        ]);
        $valueId = $this->databaseInsert('ip_custom_values', ['custom_values_field' => $fieldId, 'custom_values_value' => 'unused']);

        /* Act */
        $response = $this->post('/custom_values/delete/' . $valueId, []);

        /* Assert */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseMissing('ip_custom_values', ['custom_values_id' => $valueId]);
    }

    /**
     * Renames the table away for the request, in the production environment.
     */
    private function hideTable(string $table): void
    {
        /* Production runs with db_debug off, which is where CodeIgniter returns false for a rejected query;
           in the testing environment it throws before the model ever sees the failure. */
        $this->withServer(['CI_ENV' => 'production']);
        $this->databaseRunScript("RENAME TABLE {$table} TO {$table}_hidden");
        $this->hiddenTable = $table;
    }

    /**
     * Puts a hidden table back; a no-op when none is hidden.
     */
    private function showTable(): void
    {
        if ($this->hiddenTable === null) {
            return;
        }

        $this->databaseRunScript("RENAME TABLE {$this->hiddenTable}_hidden TO {$this->hiddenTable}");
        $this->hiddenTable = null;
    }
}
